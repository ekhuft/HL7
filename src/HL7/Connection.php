<?php

declare(strict_types=1);

namespace Aranyasen\HL7;

use Aranyasen\Exceptions\HL7ConnectionException;
use Aranyasen\Exceptions\HL7Exception;
use Aranyasen\HL7\Segments\MSH;
use Exception;
use Socket;

/**
 * Usage:
 * ```php
 * $connection = new Connection('127.0.0.1', 5002);
 * $req = new Message();
 * // ... set some request attributes
 * $response = $connection->send($req);
 * $response->toString(); // Read ACK message from remote
 * ```
 *
 * The Connection object represents the tcp connection to the HL7 message broker. The Connection has only one public
 * method (apart from the constructor), send(). The 'send' method takes a Message object as argument, and also
 * returns a Message object. The send method can be used more than once, before the connection is closed.
 * Connection is closed automatically when the connection object is destroyed.
 *
 * The Connection object holds the following fields:
 *
 * MESSAGE_PREFIX
 *
 * The prefix to be sent to the HL7 server to initiate the
 * message. Defaults to \013.
 *
 * MESSAGE_SUFFIX
 * End of message signal for HL7 server. Defaults to \034\015.
 *
 */
class Connection
{
    protected Socket $socket;
    protected int $timeout;
    protected array $hl7Globals;

    /** # Octal 13 (Hex: 0B): Vertical Tab */
    protected string $MESSAGE_PREFIX = "\013";

    /** # 34 (Hex: 1C): file separator character, 15 (Hex: 0D): Carriage return */
    protected string $MESSAGE_SUFFIX = "\034\015";

    /**
     * Creates a connection to a HL7 server, or throws exception when a connection could not be established.
     *
     * @param string $host Host to connect to
     * @param int $port Port to connect to
     * @param int $timeout Connection timeout
     * @throws HL7ConnectionException
     */
    public function __construct(string $host, int $port, int $timeout = 10, array $hl7Globals = [])
    {
        if (!extension_loaded('sockets')) {
            throw new HL7ConnectionException('Please install ext-sockets to run Connection');
        }
        $this->setSocket($host, $port, $timeout);
        $this->timeout = $timeout;
        $this->hl7Globals = $hl7Globals;
    }

    /**
     * Create a client-side TCP socket
     *
     * @param int $timeout Connection timeout
     * @throws HL7ConnectionException
     */
    protected function setSocket(string $host, int $port, int $timeout = 10): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$socket) {
            $this->throwSocketError('Failed to create socket');
        }

        if (!socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $timeout, 'usec' => 0])) {
            $this->throwSocketError('Unable to set timeout on socket');
        }

        if (!socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout, 'usec' => 0])) {
            $this->throwSocketError('Unable to set timeout on socket');
        }

        if (!socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            $this->throwSocketError('Unable to set reuse-address on socket');
        }

        // Uncomment this if server requires a certain client-side port to be used
        // if (!socket_bind($socket, "0.0.0.0", $localPort)) {
        //     $this->throwSocketError('Unable to bind socket');
        // }

        $result = null;
        try {
            $result = socket_connect($socket, $host, $port);
        } catch (Exception) {
            $this->throwSocketError("Failed to connect to server ($host:$port)");
        }
        if (!$result) {
            $this->throwSocketError("Failed to connect to server ($host:$port)");
        }

        $this->socket = $socket;
    }

    /**
     * @throws HL7ConnectionException
     */
    protected function throwSocketError(string $message): void
    {
        throw new HL7ConnectionException($message . ': ' . socket_strerror(socket_last_error()));
    }

    /**
     * Sends a Message object over this connection.
     *
     * @param  string  $responseCharEncoding  The expected character encoding of the response.
     * @param  bool  $noWait  Do no wait for ACK. Helpful for building load testing tools...
     * @throws HL7ConnectionException
     * @throws HL7Exception
     */
    public function send(Message $msg, string $responseCharEncoding = 'UTF-8', bool $noWait = false, bool $verifyControlId = true): ?Message
    {
        $requestHeader = $msg->getFirstSegmentInstance('MSH');

        assert($requestHeader instanceof MSH);

        $message = $this->MESSAGE_PREFIX . $msg->toString(true) . $this->MESSAGE_SUFFIX; // As per MLLP protocol

        if (!socket_write($this->socket, $message, strlen($message))) {
            throw new HL7Exception("Could not send data to server: " . socket_strerror(socket_last_error()));
        }

        if ($noWait) {
            return null;
        }

        $responses = [];

        $incomingResponse = null;

        $startTime = time();

        // Start buffering responses sent to the socket
        while (($buf = socket_read($this->socket, 1024)) !== false) {
            $incomingResponse .= $buf;

            // If we've received a complete message, parse it 
            if (preg_match('/' . $this->MESSAGE_SUFFIX . '$/', $incomingResponse)) {
                $message = $this->handleResponse($incomingResponse, $responseCharEncoding);

                // If set to verify the Message Control ID, but the ID does not match, store the complete response
                if ($verifyControlId && !$this->verifyMessageControlID($requestHeader->getMessageControlId(), $message)) {
                    $responses[] = $incomingResponse;
                    $incomingResponse = null;
                    continue;
                }

                // If verified (or not verifying) return the response
                return $message;
            }

            // If no complete responses are received before the timeout, time-out with partial response
            if (empty($responses) && (time() - $startTime) > $this->timeout) {
                throw new HL7ConnectionException(
                    "Response partially received. Timed out listening for end-of-message from server"
                );
            }
        }

        // If no responses have been received, timeout
        throw new HL7ConnectionException("No response received within {$this->timeout} seconds");
    }

    protected function handleResponse(string $raw, string $charEncoding): Message
    {
        // Remove message prefix and suffix added by the MLLP server
        $raw = preg_replace('/^' . $this->MESSAGE_PREFIX . '/', '', $raw);
        $raw = preg_replace('/' . $this->MESSAGE_SUFFIX . '$/', '', $raw);

        // Set character encoding
        $raw = mb_convert_encoding($raw, $charEncoding);

        // Construct our response
        return new Message($raw, $this->hl7Globals, true, true);
    }

    protected function verifyMessageControlID(string $expectedMessageControlID, Message $response): bool
    {
        $responseHeader = $response->getFirstSegmentInstance('MSH');

        assert($responseHeader instanceof MSH);

        return $expectedMessageControlID == $responseHeader->getMessageControlId();
    }

    /*
     * Return the raw socket opened/used by this class
     */
    public function getSocket(): Socket
    {
        return $this->socket;
    }

    /**
     * Close the socket
     * TODO: Close only when the socket is open
     */
    private function close(): void
    {
        try {
            socket_close($this->socket);
        } catch (Exception $e) {
            echo 'Failed to close socket: ' . socket_strerror(socket_last_error()) . PHP_EOL;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
