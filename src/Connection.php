<?php
namespace Xvq\PhpAdb;

use Xvq\PhpAdb\exception\AdbConnectionException;
use Xvq\PhpAdb\exception\AdbException;
use RuntimeException;


class Connection
{
    public readonly string $host;
    public readonly int $port;
    public readonly int $timeout;

    /** @var resource|null */
    private $socket = null;


    public function __construct(string $host = '127.0.0.1', int $port = 5037, int $timeout = 5)
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    public function connect(): void
    {
        $remote = "tcp://$this->host:$this->port";
        $context = stream_context_create([]);
        $this->socket = @stream_socket_client(
            $remote,
            $errno,
            $errStr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->socket) {
            throw new AdbConnectionException("ADB connection failed: $errStr ($errno)");
        }

        stream_set_timeout($this->socket, $this->timeout);
        stream_set_blocking($this->socket, true);
    }

    public function write(string $data): void
    {
        $sent = 0;
        $len = strlen($data);

        while ($sent < $len) {
            $n = fwrite($this->socket, substr($data, $sent));
            if ($n === false) {
                throw new AdbConnectionException("Failed to write to socket");
            }
            $sent += $n;
        }
    }

    public function read(int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($this->socket, $length - strlen($buffer));
            if ($chunk === '') {
                break;
            }
            if ($chunk === false) {
                throw new AdbConnectionException("Failed to read from socket");
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    public function readExact(int $length): string
    {
        $data = $this->read($length);
        if (strlen($data) < $length) {
            throw new AdbConnectionException("Unexpected EOF while reading ($length bytes requested)");
        }

        return $data;
    }

    public function readFully(int $length = 8192): string
    {
        $output = '';
        while (true) {
            $chunk = fread($this->socket, $length);
            if ($chunk === '' || $chunk === false) break;
            $output .= $chunk;
        }

        return $output;
    }

    public function readStream(int $length = 8192): \Generator
    {
        stream_set_blocking($this->socket, false);

        try {
            while (!feof($this->socket)) {
                $read = [$this->socket];
                $write = $except = null;

                $changed = stream_select($read, $write, $except, 0, 200000); // 0.2秒

                if ($changed > 0) {
                    $chunk = $this->readFully($length);
                    if ($chunk !== '') {
                        yield $chunk;
                    }
                }
            }
        } finally {
            stream_set_blocking($this->socket, true);
        }
    }

    public function close(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
