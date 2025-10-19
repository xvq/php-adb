<?php
namespace Xvq\PhpAdb;

use Xvq\PhpAdb\DTO\DeviceInfo;
use Xvq\PhpAdb\enum\Network;
use Xvq\PhpAdb\extension\App;
use Xvq\PhpAdb\extension\Input;
use Xvq\PhpAdb\extension\Screenshot;
use Xvq\PhpAdb\extension\Shell;
use Xvq\PhpAdb\extension\File;

class Device
{
    public readonly DeviceInfo $deviceInfo;

    private AdbClient $adbClient;

    public readonly Shell $shell;
    public readonly File $file;
    public readonly Screenshot $screenshot;
    public readonly Input $input;
    public readonly App $app;

    public function __construct(AdbClient $adbClient, DeviceInfo $deviceInfo)
    {
        $this->adbClient = $adbClient;
        $this->deviceInfo = $deviceInfo;

        $this->shell = new Shell($this);
        $this->file = new File($this);
        $this->screenshot = new Screenshot($this);
        $this->input = new Input($this);
        $this->app = new App($this);
    }

    /**
     * Open a transport connection to a specific device, optionally sending an ADB command.
     *
     * @param string $command Optional command to send after opening transport
     * @param int $timeout Connection timeout in seconds (default 3)
     * @return Transport The transport connection to the device
     */
    public function openTransport(string $command = "", int $timeout = 5): Transport
    {
        $transport = new Transport($this->adbClient->host, $this->adbClient->port, $timeout);

        if($command){
            if($this->deviceInfo->transport_id){
                $transport->sendCommand("host-transport-id:{$this->deviceInfo->transport_id}:$command");
            }else if ($this->deviceInfo->serial){
                $transport->sendCommand("host-serial:{$this->deviceInfo->serial}:$command");
            }
        }else{
            if($this->deviceInfo->transport_id){
                $transport->sendCommand("host:transport-id:{$this->deviceInfo->transport_id}");
            }else if ($this->deviceInfo->serial){
                if ($this->adbClient->getServerVersion() >= 41){
                    $transport->sendCommand("host:tport:serial:{$this->deviceInfo->serial}");
                    $transport->connection->read(8);
                }else{
                    $transport->sendCommand("host:transport:{$this->deviceInfo->serial}");
                }
            }
        }

        return $transport;
    }

    /**
     * Get the Android version of the device.
     *
     * @return string
     */
    public function androidVersion(): string
    {
        return $this->shell->getProp("ro.build.version.release");
    }

    /**
     * Get the device information.
     *
     * @return DeviceInfo
     */
    public function getInfo(): DeviceInfo
    {
        return $this->deviceInfo;
    }

    /**
     * Get the current state of the device.
     *
     * @return string
     */
    public function getState(): string
    {
        return $this->openTransport("get-state")->readStringBlock();
    }

    /**
     * Get the device path on the ADB server.
     *
     * @return string
     */
    public function getDevPath(): string
    {
        return $this->openTransport("get-devpath")->readStringBlock();
    }

    /**
     * Get the list of features supported by the device.
     *
     * @return array List of feature strings reported by the device.
     */
    public function getFeatures(): array
    {
        $transport = $this->openTransport("features")->readStringBlock();

        return explode(",",$transport);
    }

    /**
     * Restart the ADB daemon on the device with root privileges.
     *
     * Example return:
     *   "cannot run as root in production builds"
     *
     * @return string Response from the device
     */
    public function root(): string
    {
        $transport = $this->openTransport();

        return $transport->requestWithFully("root:");
    }

    /**
     * Restart the ADB daemon on the device in TCP/IP mode on the specified port.
     *
     * @param string $port Port number to use for TCP/IP connection
     * @return string Response from the device
     */
    public function tcpIp(string $port): string
    {
        $transport = $this->openTransport();

        return $transport->requestWithFully("tcpip:$port");
    }

    /**
     * Set up port forwarding from the local machine to the device.
     *
     * @param string $local Local port or socket
     * @param string $remote Remote port or socket on the device
     * @param bool $noRebind If true, prevents rebinding an existing forward
     */
    public function forward(string $local, string $remote, bool $noRebind = false): void
    {
        $cmd = 'forward' . ($noRebind ? ':norebind' : '') . ":{$local};{$remote}";
        $transport = $this->openTransport($cmd);
    }

    /**
     * Get a list of all current port forwards from the device.
     *
     * @return array List of forwards, each containing:
     *               - 'serial': device serial
     *               - 'local' : local port or socket on the host
     *               - 'remote': remote port or socket on the device
     */
    public function forwardList(): array
    {
        $c = $this->openTransport('list-forward');
        $content = $c->readStringBlock();
        $items = [];
        foreach (explode("\n", trim($content)) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) !== 3) {
                continue;
            }

            $items[] = [
                'serial' => $parts[0],
                'local'  => $parts[1],
                'remote' => $parts[2],
            ];
        }

        return $items;
    }

    /**
     * Reverse a port from the device to the host.
     *
     * @param string $local Local port or socket on the device
     * @param string $remote Remote port or socket on the host
     * @param bool $noRebind Whether to avoid rebinding if the reverse already exists
     */
    public function reverse(string $local, string $remote, bool $noRebind = false): void
    {
        $cmd = 'reverse:forward' . ($noRebind ? ':norebind' : '') . ":{$local};{$remote}";
        $transport = $this->openTransport($cmd);
        $transport->checkOkay();
    }

    /**
     * Get a list of all current reverse forwards from the device.
     *
     * @return array List of reverse forwards, each containing:
     *               - 'local' : local port or socket on the host
     *               - 'remote': remote port or socket on the device
     */
    public function reverseList(): array
    {
        $transport = $this->openTransport();
        $content = $transport->requestWithStingBlock("reverse:list-forward");
        $items = [];

        foreach (explode("\n", trim($content)) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) !== 3) {
                continue;
            }

            $items[] = [
                'local'  => $parts[0],
                'remote' => $parts[1],
            ];
        }

        return $items;
    }

    /**
     * Create a connection to a device using the specified network type and address.
     *
     * @param Network $network Network type (e.g., TCP, UNIX, LOCAL_ABSTRACT)
     * @param string  $address Target address or port
     * @return Connection Established connection object
     */
    public function createConnection(Network $network, string $address): Connection
    {
        $transport = $this->openTransport();
        match ($network){
            Network::TCP => $transport->sendCommand("tcp:$address"),
            Network::UNIX, Network::LOCAL_ABSTRACT => $transport->sendCommand("localabstract:$address"),
            Network::LOCAL_FILESYSTEM,
            Network::LOCAL,
            Network::DEV,
            Network::LOCAL_RESERVED => $transport->sendCommand("$network->value:$address"),
        };

        return $transport->connection;
    }
}
