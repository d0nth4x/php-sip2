<?php

namespace lordelph\SIP2;

/**
 * SIP2Client Class
 *
 * This class provides a method of communicating with an Integrated
 * Library System using 3M's SIP2 standard.
 *
 * @licence    https://opensource.org/licenses/MIT
 * @copyright  John Wohlers <john@wohlershome.net>
 * @copyright  Paul Dixon <paul@elphin.com>
 */

use lordelph\SIP2\Exception\RuntimeException;
use lordelph\SIP2\Request\SIP2Request;
use lordelph\SIP2\Response\SIP2Response;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket\Raw\Factory;
use Socket\Raw\Socket;

/**
 * SIP2Client provides a simple client for SIP2 library services
 *
 * In the specification, and the comments below, 'SC' (or Self Check) denotes the client, and ACS (or Automated
 * Circulation System) denotes the server.
 */
class SIP2Client implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    //-----------------------------------------------------
    // request options
    //-----------------------------------------------------

    /**
     * @var array name=>value request defaults used for every request
     */
    private $default=[];

    //-----------------------------------------------------
    // connection handling
    //-----------------------------------------------------

    /** @var int maximum number of resends in the event of CRC failure */
    public $maxretry = 3;

    /** @var Socket */
    private $socket;

    /** @var Factory injectable factory for creating socket connections */
    private $socketFactory;

    /** @var bool CRC checks can be disabled by setting this to false */
    private static $enableCRCChecks = true;

    /**
     * Constructor allows you to provide a PSR-3 logger, but you can also use the setLogger method
     * later on.
     *
     * @param LoggerInterface|null $logger
     */
    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->setDefault('InstitutionId', 'WohlersSIP');
    }

    public function setDefault($name, $value)
    {
        $this->default[$name] = $value;
    }

    /**
     * Can be used to disable CRC checks
     *
     * Some SIP2 services have been observed producing invalid CRCs when extended/UTF-8 chars are involved. As
     * the CRC was originally part of the protocol for noisy serial lines, it serves little purpose in error
     * checking when connecting via TCP/IP, but disable at your own risk!
     *
     * @param bool $enable
     */
    public static function enableCRCCheck($enable)
    {
        self::$enableCRCChecks = $enable;
    }

    public static function isCRCCheckEnabled()
    {
        return self::$enableCRCChecks;
    }


    /**
     * Allows an alternative socket factory to be injected. The allows us to
     * mock socket connections for testing
     *
     * @param Factory $factory
     */
    public function setSocketFactory(Factory $factory)
    {
        $this->socketFactory = $factory;
    }

    /**
     * Get the current socket factory, creating a default one if necessary
     * @return Factory
     */
    private function getSocketFactory()
    {
        if (is_null($this->socketFactory)) {
            $this->socketFactory = new Factory(); //@codeCoverageIgnore
        }
        return $this->socketFactory;
    }


    /**
     * @param SIP2Request $request
     * @return SIP2Response
     * @throws RuntimeException if server fails to produce a valid response
     */
    public function sendRequest(SIP2Request $request) : SIP2Response
    {
        foreach ($this->default as $name => $value) {
            $request->setDefault($name, $value);
        }

        $raw = $this->getRawResponse($request);
        return SIP2Response::parse($raw);
    }

    private function getRawResponse(SIP2Request $request, $depth = 0)
    {
        $result = '';
        $terminator = '';

        $message = $request->getMessageString();

        $this->logger->debug('SIP2: Sending SIP2 request '.trim($message));
        $this->socket->write($message);

        $this->logger->debug('SIP2: Request Sent, Reading response');

        while ($terminator != "\x0D") {
            //@codeCoverageIgnoreStart
            try {
                $terminator = $this->socket->recv(1, 0);
            } catch (\Exception $e) {
                break;
            }
            //@codeCoverageIgnoreEnd

            $result = $result . $terminator;
        }
        //if the remote end uses CRLF as a terminator, we might have a stray LF in our buffer - by trimming
        //the responses, we ensure those don't upset us
        $result=trim($result);

        $this->logger->info("SIP2: result={$result}");

        // test message for CRC validity
        if (SIP2Response::checkCRC($result)) {
            $this->logger->debug("SIP2: Message from ACS passed CRC check");
        } else {
            //CRC check failed, we resend the request
            if ($depth < $this->maxretry) {
                $depth++;
                $this->logger->warning("SIP2: Message failed CRC check, retry {$depth})");
                $result = $this->getRawResponse($request, $depth);
            } else {
                $errMsg="SIP2: Failed to get valid CRC after {$this->maxretry} retries.";
                $this->logger->critical($errMsg);
                throw new RuntimeException($errMsg);
            }
        }

        return $result;
    }

    /**
     * Connect to ACS via SIP2
     *
     * The $bind parameter can be useful where a machine which has multiple outbound connections and its important
     * to control which one is used (normally because the remote SIP2 service is firewalled to particular IPs
     *
     * @param string $address ip:port of remote SIP2 service
     * @param string|null $bind local ip to bind socket to
     * @param int $timeout number of seconds to allow for connection to succeed
     */
    public function connect($address, $bind = null, $timeout = 15)
    {
        $this->logger->debug("SIP2Client: Attempting connection to $address");

        try {
            $this->socket = $this->createClient($address, $bind, $timeout);
        } catch (\Exception $e) {
            $this->socket = null;
            $this->logger->error("SIP2Client: Failed to connect: " . $e->getMessage());
            throw new RuntimeException("Connection failure", 0, $e);
        }

        $this->logger->debug("SIP2Client: connected");
    }

    /**
     * A 'missing' factory method which allows us to establish a socket bound to a particular interface
     * @param $address
     * @param $bind
     * @param $timeout
     * @return Socket
     * @throws \Exception
     */
    private function createClient($address, $bind, $timeout) : Socket
    {
        $factory = $this->getSocketFactory();

        $socket = $factory->createFromString($address, $scheme);

        try {
            if (!empty($bind)) {
                $this->logger->debug("SIP2Client: binding socket to $bind");
                $socket->bind($bind);
            }

            if ($timeout === null) {
                $socket->connect($address);
            } else {
                // connectTimeout enables non-blocking mode, so turn blocking on again
                $socket->connectTimeout($address, $timeout);
                $socket->setBlocking(true);
            }
        } catch (\Exception $e) {
            $socket->close();
            throw $e;
        }

        return $socket;
    }


    /**
     * Disconnect from ACS
     */
    public function disconnect()
    {
        $this->socket->close();
        $this->socket = null;
    }
}
