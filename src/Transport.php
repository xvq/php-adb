<?php

namespace Xvq\PhpAdb;

use Xvq\PhpAdb\Exception\AdbConnectionException;
use Xvq\PhpAdb\Exception\AdbException;
use Xvq\PhpAdb\Exception\AdbProtocolException;

class Transport
{
    public readonly Connection $connection;

    public function __construct(string $host = '127.0.0.1', int $port = 5037, int $timeout = 5)
    {
        $this->connection = new Connection($host, $port, $timeout);
        $this->connection->connect();
    }

    /**
     * Send a command to the device and verify the OKAY response.
     *
     * @param string $cmd The command to send
     */
    public function sendCommand(string $cmd): void
    {
        $payload = sprintf("%04x%s", strlen($cmd), $cmd);
        $this->connection->write($payload);
        $this->checkOkay();
    }

    public function requestWithStringBlock(string $cmd): string
    {
        $this->sendCommand($cmd);

        return $this->readStringBlock();
    }

    public function requestWithFully(string $cmd): string
    {
        $this->sendCommand($cmd);

        return $this->readFully();
    }

    public function requestWithStream(string $cmd): \Generator
    {
        $this->sendCommand($cmd);

        return $this->readStream();
    }

    public function readStringBlock(): string
    {
        $lenStr = $this->connection->read(4);

        if (!preg_match('/^[0-9a-fA-F]{4}$/', $lenStr)) {
            throw new AdbConnectionException("Invalid string block length header: {$lenStr}");
        }

        $len = hexdec($lenStr);

        if ($len === 0) {
            return '';
        }

        return rtrim($this->connection->readExact($len));
    }

    public function readFully(): string
    {
        return rtrim($this->connection->readFully());
    }

    public function readExact(int $len): string
    {
        return $this->connection->readExact($len);
    }

    public function readStream(): \Generator
    {
        return $this->connection->readStream();
    }

    public function checkOkay(): void
    {
        $status = $this->connection->readExact(4);

        match ($status){
            'OKAY' => true,
            'FAIL' => throw new AdbProtocolException("ADB request failed: {$this->readStringBlock()}"),
            default => throw new AdbConnectionException("Unexpected response: $status"),
        };
    }
}