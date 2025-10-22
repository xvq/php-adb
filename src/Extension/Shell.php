<?php

namespace Xvq\PhpAdb\Extension;

use Xvq\PhpAdb\Device;
use Xvq\PhpAdb\DTO\ShellReturn;
use Xvq\PhpAdb\Enum\KeyCode;
use Xvq\PhpAdb\Exception\AdbException;

class Shell
{
    public function __construct(
        private readonly Device $device
    ){}

    /**
     * Execute shell command
     *
     * @param string|array $cmd The command to execute, can be a string or string array
     * @param bool $stream Whether to use streaming transmission, default is false
     * @param int $timeout Timeout in seconds
     * @return \Generator|string When $stream is true, returns Generator object, otherwise returns string
     */
    public function run(string|array $cmd, bool $stream = false, int $timeout = 5): \Generator|string
    {
        $shellCmd = "shell:" . (is_array($cmd) ? implode(' ', $cmd) : $cmd);
        $transport = $this->device->openTransport(timeout: $timeout);
        if ($stream) {
            return $transport->requestWithStream($shellCmd);
        }else{
            return $transport->requestWithFully($shellCmd);
        }
    }

    /**
     * Execute a shell command via adb shell,v2 protocol.
     *
     * @param string $cmd Command string to execute
     * @param int $timeout Timeout in seconds
     * @return ShellReturn
     * @throws AdbException
     */
    public function runV2(string $cmd, int $timeout = 5): ShellReturn
    {
        $transport = $this->device->openTransport(timeout: $timeout);
        $transport->sendCommand("shell,v2:" . $cmd);

        $stdoutBuffer = '';
        $stderrBuffer = '';
        $outputBuffer = '';
        $exitCode = 255;

        while (true) {
            $header = $transport->readExact(5);
            $msgId = ord($header[0]);
            $length = unpack('V', substr($header, 1, 4))[1]; // little-endian uint32

            if ($length === 0) {
                continue;
            }

            $data = $transport->readExact($length);

            if ($msgId === 1) {
                $stdoutBuffer .= $data;
                $outputBuffer .= $data;
            } elseif ($msgId === 2) {
                $stderrBuffer .= $data;
                $outputBuffer .= $data;
            } elseif ($msgId === 3) {
                $exitCode = ord($data[0]);
                break;
            }
        }

        return new ShellReturn(
            $cmd,
            $exitCode,
            $outputBuffer,
            $stdoutBuffer,
            $stderrBuffer
        );
    }

    /**
     * Get system property value
     *
     * @param string $prop Property name
     * @return string Returns the property value
     */
    public function getProp(string $prop): string
    {
        return $this->run('getprop ' . $prop);
    }

    /**
     * Reboot the device
     *
     * @return void
     */
    public function reboot(): void
    {
        $this->run('reboot');
    }

    /**
     * Enable or disable airplane mode on the device.
     *
     * @param bool $enable True to enable, false to disable
     */
    function switchAirplane(bool $enable): void
    {
        $settingCmd = ["settings", "put", "global", "airplane_mode_on", $enable ? "1" : "0"];
        $broadcastCmd = [
            "am",
            "broadcast",
            "-a", "android.intent.action.AIRPLANE_MODE",
            "--ez", "state", $enable ? "true" : "false"
        ];

        $this->run($settingCmd);
        $this->run($broadcastCmd);
    }

    /**
     * Enable or disable Wi-Fi on the device.
     *
     * @param bool $enable True to enable, false to disable
     */
    function switchWifi(bool $enable): void
    {
        $settingCmd = ["svc", "wifi", $enable ? "enable" : "disable"];
        $this->run($settingCmd);
    }

    /**
     * Get the current screen rotation.
     *
     * @return int  Possible values: [0, 1, 2, 3], corresponding to 0°, 90°, 180°, and 270°.
     * @throws AdbException
     */
    function getRotation(): int
    {
        $output = $this->run('dumpsys display');

        if (preg_match('/orientation=(\d+)/', $output, $m)) {
            return (int)$m[1];
        }

        throw new AdbException("Failed to parse orientation from dumpsys display output");
    }

    /**
     * Get the device's screen size.
     *
     * @param bool|null $landscape Optional. If true, returns width/height swapped for landscape orientation.
     * @return array Associative array with keys 'width' and 'height'
     * @throws AdbException If the screen size cannot be determined
     */
    function getWindowSize(?bool $landscape = null): array
    {
        $output = $this->run('wm size');

        if (preg_match('/Override size:\s*(\d+)x(\d+)/', $output, $m)) {
            $width = (int)$m[1];
            $height = (int)$m[2];
        }elseif (preg_match('/Physical size:\s*(\d+)x(\d+)/', $output, $m)) {
            $width = (int)$m[1];
            $height = (int)$m[2];
        } else {
            throw new AdbException("Unable to parse resolution from wm size output");
        }

        $landscape ??= ($this->getRotation() % 2 === 1);
        return $landscape
            ? ['width'=>$height,'height'=>$width]
            : ['width'=>$width,'height'=>$height];
    }

    /**
     * Get the device WLAN IP address.
     *
     * @return string
     * @throws AdbException
     */
    public function getWlanIp(): string
    {
        $result = $this->run(['ifconfig', 'wlan0']);
        if (preg_match('/inet\s*addr:(.*?)\s/s', $result, $matches)) {
            return $matches[1];
        }

        // Huawei P30, has no ifconfig
        $result = $this->run(['ip', 'addr', 'show', 'dev', 'wlan0']);
        if (preg_match('/inet (\d+.*?\/\d+)/', $result, $matches)) {
            return explode('/', $matches[1])[0];
        }

        // On VirtualDevice, might use eth0
        $result = $this->run(['ifconfig', 'eth0']);
        if (preg_match('/inet\s*addr:(.*?)\s/s', $result, $matches)) {
            return $matches[1];
        }

        throw new AdbException("Failed to parse WLAN IP");
    }

    /**
     * Check if the device screen is on.
     */
    public function isScreenOn(): bool
    {
        $output = $this->run(['dumpsys', 'power']);
        return str_contains($output, 'mHoldingDisplaySuspendBlocker=true');
    }

    /**
     * Open a URL in the device's default browser.
     */
    public function openBrowser(string $url): void
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $this->run('am start -a android.intent.action.VIEW -d ' . escapeshellarg($url));
    }

    /**
     * Dump the current UI hierarchy of the device as XML.
     *
     * Returns:
     *   XML string representing the UI layout.
     *
     * @return string
     * @throws AdbException If uiautomator dump fails or output is invalid
     */
    public function dumpHierarchy(): string
    {
        $target = '/data/local/tmp/uidump.xml';

        $output = $this->device->shell->run("rm -f {$target}; uiautomator dump {$target} && echo success");

        if (str_contains($output, 'ERROR') || !str_contains($output, 'success')) {
            throw new AdbException("uiautomator dump failed: " . $output);
        }

        $buf = '';
        foreach ($this->device->file->read($target) as $chunk) {
            $buf .= $chunk;
        }

        $xmlData = mb_convert_encoding($buf, 'UTF-8');

        if (!str_starts_with($xmlData, '<?xml')) {
            throw new AdbException("Dump output is not valid XML: " . $xmlData);
        }

        return $xmlData;
    }

    /**
     * Get battery info from the device.
     *
     * Returns an associative array with keys:
     *  - ac_powered, usb_powered, wireless_powered, dock_powered (bool)
     *  - max_charging_current, max_charging_voltage, charge_counter, status, health, level, scale, voltage (int)
     *  - temperature (float, °C), technology (string)
     *
     * @return array
     */
    public function battery(): array
    {
        $output = $this->run(['dumpsys', 'battery']);

        $shellKvs = [];
        foreach (explode("\n", $output) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            list($key, $val) = explode(':', $line, 2);
            $shellKvs[trim($key)] = trim($val);
        }

        $getKey = function(string $k, callable $mapFunction) use ($shellKvs) {
            if (isset($shellKvs[$k])) {
                return $mapFunction($shellKvs[$k]);
            }
            return null;
        };

        $toBool = fn($v) => strtolower($v) === 'true';

        return [
            'ac_powered' => $getKey('AC powered', $toBool),
            'usb_powered' => $getKey('USB powered', $toBool),
            'wireless_powered' => $getKey('Wireless powered', $toBool),
            'dock_powered' => $getKey('Dock powered', $toBool),
            'max_charging_current' => $getKey('Max charging current', 'intval'),
            'max_charging_voltage' => $getKey('Max charging voltage', 'intval'),
            'charge_counter' => $getKey('Charge counter', 'intval'),
            'status' => $getKey('status', 'intval'),
            'health' => $getKey('health', 'intval'),
            'present' => $getKey('present', $toBool),
            'level' => $getKey('level', 'intval'),
            'scale' => $getKey('scale', 'intval'),
            'voltage' => $getKey('voltage', 'intval'),
            'temperature' => $getKey('temperature', fn($x) => intval($x) / 10),
            'technology' => $shellKvs['technology'] ?? null,
        ];
    }
}