<?php
namespace Xvq\PhpAdb;

use Xvq\PhpAdb\DTO\DeviceInfo;
use Xvq\PhpAdb\Exception\AdbConnectionException;
use Xvq\PhpAdb\Exception\AdbException;

class AdbClient
{
    public function __construct(
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 5037,
        public readonly int $timeout = 5
    ){}

    /**
     * Open a connection (transport) to the ADB server.
     *
     * @param int|null $timeout
     * @return Transport The transport connection
     */
    public function openTransport(?int $timeout = null): Transport
    {
        return new Transport($this->host, $this->port, $timeout ?? $this->timeout);
    }

    /**
     * Get an array of all connected device serial numbers.
     *
     * @return string[] Array of device serials
     */
    public function getAllDeviceSerials(): array
    {
        $resp = $this->openTransport()->requestWithStringBlock("host:devices");
        $lines = array_filter(explode("\n", trim($resp)));
        $serials = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (!empty($parts[0])) {
                $serials[] = $parts[0];
            }
        }

        return $serials;
    }

    /**
     * Get a list of all connected devices as Device objects.
     *
     * @return Device[] Array of Device instances
     */
    public function getAllDevices(): array
    {
        $resp = $this->openTransport()->requestWithStringBlock("host:devices-l");
        $devices = $this->parseDevicesList($resp);

        return array_map(
            function ($d) {
                return new Device($this, new DeviceInfo(
                    $d['serial'],
                    $d['transport_id'],
                    $d['product'],
                    $d['model'],
                    $d['device']
                ));
            },
            $devices
        );
    }

    /**
     * Get the ADB server version.
     *
     * @return string
     */
    public function getServerVersion(): string
    {
        $resp = $this->openTransport()->requestWithStringBlock("host:version");

        return trim(hexdec($resp));
    }

    /**
     * Get a device by serial number or transport ID.
     *
     * @param string|null $serial Optional device serial
     * @param int|null $transportId Optional transport ID
     * @return Device The selected device
     * @throws AdbException If no device is found
     */
    public function device(?string $serial = null, ?int $transportId = null): Device
    {
        $resp = $this->openTransport()->requestWithStringBlock("host:devices-l");
        $devices = $this->parseDevicesList($resp);

        if (empty($devices)) throw new AdbException("No devices available");

        $result = match (true){
            !empty($serial) => array_filter($devices, fn($d) => $d['serial'] === $serial),
            !empty($transportId) => array_filter($devices, fn($d) => intval($d['transport_id']) === $transportId),
            default => $devices
        };

        if (empty($result)) throw new AdbException("Device not found");

        $device = reset($result);

        return new Device($this, new DeviceInfo(
            $device['serial'],
            $device['transport_id'],
            $device['product'],
            $device['model'],
            $device['device']
        ));
    }

    /**
     * Connect to a remote device.
     *
     * @param string $address The target address to connect to (e.g., "127.0.0.1:5555").
     * @param int $timeout The timeout for the connection in seconds.
     * @return string The response from the ADB server after sending the connect command.
     * @throws AdbConnectionException If the connection fails or no response is received.
     */
    public function connect(string $address, int $timeout = 5): string
    {
        return $this->openTransport($timeout)->requestWithStringBlock("host:connect:$address");
    }

    /**
     * Disconnect a remote device.
     *
     * @param string $address The target address to disconnect from (e.g., "127.0.0.1:5555").
     * @return string The response from the ADB server after sending the disconnect command.
     * @throws AdbConnectionException If the disconnection fails or no response is received.
     */
    public function disconnect(string $address): string
    {
        return $this->openTransport()->requestWithStringBlock("host:disconnect:$address");
    }

    /**
     * Parse raw device list from ADB into structured array.
     *
     * @param string $deviceListRaw Raw device list output from ADB
     * @return array Parsed array of devices with keys: serial, product, model, device, transport_id
     */
    private function parseDevicesList(string $deviceListRaw): array
    {
        $defaultKeys = ['serial' => null, 'product' => null, 'model' => null, 'device' => null, 'transport_id' => null];

        return array_map(
            fn($line) => array_merge(
                $defaultKeys,
                ['serial' => trim(substr($line, 0, 16))],
                array_column(
                    preg_match_all('/(\w+):(\S+)/', $line, $m, PREG_SET_ORDER) ? $m : [],
                    2,
                    1
                )
            ),
            array_filter(array_map('trim', explode("\n", $deviceListRaw)))
        );
    }
}
