<?php

namespace Xvq\PhpAdb\Extension;

use Xvq\PhpAdb\Device;
use Xvq\PhpAdb\Exception\AdbException;
use Xvq\PhpAdb\Exception\AdbProtocolException;
use Xvq\PhpAdb\Transport;
use DateTime;
use Generator;

class File
{
    private const SYNC_OKAY = "OKAY";
    private const SYNC_FAIL = "FAIL";
    private const SYNC_DENT = "DENT";
    private const SYNC_DONE = "DONE";
    private const SYNC_DATA = "DATA";

    public function __construct(
        private readonly Device $device
    ){}

    /**
     * Prepare a sync command for a given path.
     */
    private function openSync(string $path, string $cmd): Transport
    {
        $transport = $this->device->openTransport();
        $transport->sendCommand('sync:');

        // {COMMAND}{LittleEndianPathLength}{Path}
        $transport->connection->write($cmd . pack('V', strlen($path)) . $path);

        return $transport;
    }

    /**
     * Create a directory on the device
     *
     * @param string $path
     * @param bool $recursive
     */
    public function mkdir(string $path, bool $recursive = false): void
    {
        $this->device->shell->run('mkdir' . ($recursive ? ' -p ' : ' ') . $path);
    }

    /**
     * Remove a file or directory
     *
     * @param string $path
     * @param bool $recursive
     */
    public function remove(string $path, bool $recursive = false): void
    {
        $this->device->shell->run('rm' . ($recursive ? ' -r ' : ' ') . $path);
    }

    /**
     * Move or rename a file or directory
     *
     * @param string $src
     * @param string $dst
     */
    public function move(string $src, string $dst): void
    {
        $this->device->shell->run('mv ' . $src . ' ' . $dst);
    }

    /**
     * Copy a file or directory on the device.
     *
     * @param string $src
     * @param string $dst
     * @param bool $recursive
     */
    public function copy(string $src, string $dst, bool $recursive = false): void
    {
        $this->device->shell->run('cp' . ($recursive ? ' -r ' : ' ') . $src . ' ' . $dst);
    }

    /**
     * Check if a file or directory exists on the device.
     *
     * @param string $path
     * @return bool
     */
    public function exists(string $path): bool
    {
        $cmd = '[ -e ' . escapeshellarg($path) . ' ] && echo 1 || echo 0';
        $output = $this->device->shell->run($cmd);

        return trim($output) === '1';
    }

    /**
     * Get file information (mode, size, modification time) for a given path.
     *
     * @param string $path Path to the file or directory on the device
     * @return array Associative array with keys: 'mode', 'size', 'mtime', 'name'
     * @throws AdbException
     */
    public function stat(string $path): array
    {
        $transport = $this->openSync($path, "STAT");

        $response = $transport->connection->read(4);
        if ($response !== "STAT") {
            throw new AdbException("STAT response invalid for path: $path");
        }

        $raw = $transport->connection->read(12);
        [$mode, $size, $mtime] = array_values(unpack('V3', $raw));
        $mtimeFormatted = $mtime ? date('Y-m-d H:i:s', $mtime) : null;

        return [
            'mode' => $mode,
            'size' => $size,
            'mtime' => $mtimeFormatted,
            'name' => $path,
        ];
    }

    /**
     * List files in a directory using sync protocol.
     *
     * @param string $path Path to the directory on the device
     * @return array[] Each element is an associative array: ['mode' => int, 'size' => int, 'mtime' => string, 'name' => string]
     */
    public function listDirectory(string $path): array
    {
        $files = [];
        $transport = $this->openSync($path, "LIST");

        while (true) {
            $response = $transport->connection->read(4);
            if ($response === self::SYNC_DONE) {
                break;
            }

            $raw = $transport->connection->read(16);
            [$mode, $size, $mtime, $nameLength] = array_values(unpack('V4', $raw));

            $name = $transport->connection->read($nameLength);

            $files[] = [
                'mode' => $mode,
                'size' => $size,
                'mtime' => date('Y-m-d H:i:s', $mtime),
                'name' => $name,
            ];
        }

        return $files;
    }

    /**
     * Push a local file to the device.
     *
     * @param string $src Local file path
     * @param string $dst Destination path on the device
     * @param int $mode File mode (default 0755)
     * @param bool $check Whether to verify the pushed size (default: false)
     * @return int Total bytes pushed
     * @throws AdbException
     */
    public function push(string $src, string $dst, int $mode = 0755, bool $check = false): int
    {
        if (!file_exists($src)) {
            throw new AdbException("Source file does not exist: $src");
        }

        // If the destination is a directory, append the source file name
        $dstStat = $this->stat($dst);
        if (($dstStat['mode'] & 0x4000) !== 0) { // 0x4000 = S_IFDIR
            $dst = rtrim($dst, '/') . '/' . basename($src);
        }

        $handle = fopen($src, 'rb');
        if (!$handle) {
            throw new AdbException("Failed to open source file: $src");
        }

        $path = $dst . ',' . (0x8000 | $mode); // 0x8000 = S_IFREG
        $totalSize = 0;
        $transport = $this->openSync($path, "SEND");

        try {
            while (true) {
                $chunk = fread($handle, 4096);
                if ($chunk === false) {
                    throw new AdbException("Failed to read from source file");
                }
                if ($chunk === '') {
                    $mtime = time();
                    $transport->connection->write('DONE' . pack('V', $mtime));
                    break;
                }
                $transport->connection->write('DATA' . pack('V', strlen($chunk)));
                $transport->connection->write($chunk);
                $totalSize += strlen($chunk);
            }

            $status = $transport->connection->read(4);
            if ($status !== self::SYNC_OKAY) {
                throw new AdbException("Push failed with status: $status");
            }
        } finally {
            fclose($handle);
        }

        if ($check) {
            $sizeOnDevice = $this->stat($dst)['size'];
            if ($sizeOnDevice !== $totalSize) {
                throw new AdbException("Push incomplete, expected $totalSize bytes, got $sizeOnDevice bytes");
            }
        }

        return $totalSize;
    }

    /**
     * Pull a file from the device to local.
     *
     * @param string $src Source path on the device
     * @param string $dst Destination path on the local machine
     * @return int Total bytes pulled
     * @throws AdbException
     */
    public function pull(string $src, string $dst): int
    {
        $handle = fopen($dst, 'wb');
        if (!$handle) {
            throw new AdbException("Failed to open local file for writing: $dst");
        }

        $totalSize = 0;

        try {
            foreach ($this->read($src) as $chunk) {
                fwrite($handle, $chunk);
                $totalSize += strlen($chunk);
            }
        } finally {
            fclose($handle);
        }

        return $totalSize;
    }

    /**
     * Read a file from the device in chunks.
     *
     * @param string $path File path on the device
     * @return Generator Yields chunks of the file content
     */
    public function read(string $path): Generator
    {
        $transport = $this->openSync($path, "RECV");

        while (true) {
            $cmd = $transport->connection->read(4);
            if ($cmd === self::SYNC_FAIL) {
                $sizeData = $transport->connection->read(4);
                $strSize = unpack('V', $sizeData)[1];
                $errorMessage = $transport->connection->read($strSize);
                throw new AdbProtocolException($errorMessage, $path);
            } elseif ($cmd === self::SYNC_DONE) {
                break;
            } elseif ($cmd === self::SYNC_DATA) {
                $sizeData = $transport->connection->read(4);
                $chunkSize = unpack('V', $sizeData)[1];
                $chunk = $transport->connection->read($chunkSize);
                if (strlen($chunk) !== $chunkSize) {
                    throw new AdbException("Read chunk missing");
                }
                yield $chunk;
            } else {
                throw new AdbException("Invalid sync command: $cmd");
            }
        }
    }
}