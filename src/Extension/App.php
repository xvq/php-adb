<?php

namespace Xvq\PhpAdb\Extension;

use Xvq\PhpAdb\Device;
use Xvq\PhpAdb\Exception\AdbException;

class App
{
    public function __construct(
        private readonly Device $device
    ){}

    /**
     * Start an app on the device.
     *
     * If an activity is specified, uses `am start`. Otherwise uses `monkey` to launch the app.
     *
     * @param string $packageName Package name of the app
     * @param string|null $activity Optional activity name
     */
    public function start(string $packageName, ?string $activity = null): void
    {
        if ($activity) {
            $output = $this->device->shell->run([
                'am', 'start', '-n', $packageName . '/' . $activity
            ]);

            if (str_contains($output, 'SecurityException') || str_contains($output, 'Error')) {
                throw new AdbException("Failed to start activity");
            }
        } else {
            $this->device->shell->run([
                'monkey',
                '-p', $packageName,
                '-c', 'android.intent.category.LAUNCHER',
                '1'
            ]);
        }
    }

    /**
     * Force stop an app on the device.
     *
     * @param string $packageName Package name of the app
     */
    public function stop(string $packageName): void
    {
        $this->device->shell->run([
            'am', 'force-stop', $packageName
        ]);
    }

    /**
     * Clear the data of an app on the device.
     *
     * @param string $packageName Package name of the app
     */
    public function clear(string $packageName): void
    {
        $this->device->shell->run([
            'pm', 'clear', $packageName
        ]);
    }

    /**
     * Install a package from a remote path on the device.
     *
     * Returns:
     *   1 - Installation succeeded
     *   2 - Installation requires manual confirmation on the device
     *
     * @param string $remotePath Path to the remote APK on the device
     * @param bool $clean Whether to remove the APK after installation (default: false)
     * @param array $flags Installation flags (default: ["-r", "-t"])
     * @return int Installation status code (1 = success, 2 = manual confirmation)
     * @throws AdbException If the installation fails
     */
    public function install(string $remotePath, bool $clean = false, array $flags = ['-r', '-t']): int
    {
        $args = array_merge(['pm', 'install'], $flags, [$remotePath]);
        $output = $this->device->shell->run($args);

        if (empty($output)) {
            // Output is empty, likely the device prompted for manual confirmation
            return 2;
        }

        if (str_contains($output, 'Success')) {
            if ($clean) {
                $this->device->shell->run(['rm', $remotePath]);
            }
            return 1;
        }

        // Installation failed
        throw new AdbException("ADB install error: " . $output);
    }

    /**
     * Push a local APK file to the device and install it.
     *
     * The APK is uploaded to the device's /data/local/tmp/ directory,
     * installed with the specified flags, and then removed from the device.
     *
     * Returns:
     *    1 - Installation succeeded
     *    2 - Installation requires manual confirmation on the device
     *
     * @param string $localPath Path to the APK on the local computer
     * @param array $flags Installation flags for `pm install` (default: ['-r', '-t'])
     *                     -r : Reinstall keeping app data
     *                     -t : Allow test APKs
     * @return int Installation status code (1 = success, 2 = manual confirmation)
     * @throws AdbException If the installation fails
     */
    public function pushAndInstall(string $localPath, array $flags = ['-r', '-t']): int
    {
        $dst = "/data/local/tmp/" . basename($localPath);
        $this->device->file->push($localPath, $dst,0644);

        return $this->install($dst, true, $flags);
    }

    /**
     * Uninstall an app from the device.
     *
     * @param string $packageName Package name of the app
     */
    public function uninstall(string $packageName): void
    {
        $this->device->shell->run([
            'pm', 'uninstall', $packageName
        ]);
    }

    /**
     * Get app info for a package.
     *
     * @param string $packageName
     * @return array|null Returns an associative array of app info, or null if the package doesn't exist
     */
    public function info(string $packageName): ?array
    {
        $output = $this->device->shell->run(['pm', 'path', $packageName]);
        if (!str_contains($output, 'package:')) {
            return null;
        }

        $apkPaths = preg_split('/\R/', $output);
        $apkPath = trim(explode(':', $apkPaths[0], 2)[1] ?? '');
        $subApkPaths = [];
        if (count($apkPaths) > 1) {
            foreach (array_slice($apkPaths, 1) as $p) {
                $subApkPaths[] = preg_replace('/^package:/', '', $p, 1);
            }
        }

        $output = $this->device->shell->run(['dumpsys', 'package', $packageName]);

        // versionName
        if (preg_match('/versionName=([^\s]+)/', $output, $m)) {
            $versionName = $m[1] === 'null' ? null : $m[1];
        } else {
            $versionName = null;
        }

        // versionCode
        if (preg_match('/versionCode=(\d+)/', $output, $m)) {
            $versionCode = (int)$m[1];
        } else {
            $versionCode = null;
        }

        // signature
        if (preg_match('/PackageSignatures{.*?\[(.*)]}/', $output, $m)) {
            $signature = $m[1];
        } else {
            $signature = null;
        }

        if (!$versionName && $signature === null) {
            return null;
        }

        // pkgFlags
        if (preg_match('/pkgFlags=\[\s*(.*)\s*]/', $output, $m)) {
            $pkgFlags = preg_split('/\s+/', $m[1]);
        } else {
            $pkgFlags = [];
        }

        // firstInstallTime
        if (preg_match('/firstInstallTime=([-\d]+\s+[:\d]+)/', $output, $m)) {
            $firstInstallTime = trim($m[1]);
        } else {
            $firstInstallTime = null;
        }

        // lastUpdateTime
        if (preg_match('/lastUpdateTime=([-\d]+\s+[:\d]+)/', $output, $m)) {
            $lastUpdateTime = trim($m[1]);
        } else {
            $lastUpdateTime = null;
        }

        return [
            'package_name'      => $packageName,
            'version_name'      => $versionName,
            'version_code'      => $versionCode,
            'flags'             => $pkgFlags,
            'first_install_time'=> $firstInstallTime,
            'last_update_time'  => $lastUpdateTime,
            'signature'         => $signature,
            'path'              => $apkPath,
            'sub_apk_paths'     => $subApkPaths,
        ];
    }

    /**
     * List installed packages on the device.
     *
     * @param array|null $filterList Optional filters:
     *                               -f : See associated file
     *                               -d : Show only disabled packages
     *                               -e : Show only enabled packages
     *                               -s : Show only system packages
     *                               -3 : Show only third-party packages
     *                               -i : See the installer for the packages
     *                               -u : Include uninstalled packages
     *                               --user <user_id> : Query specific user space
     * @return string[] Sorted list of package names
     */
    public function list(?array $filterList = null): array
    {
        $result = [];
        $cmd = ['pm', 'list', 'packages'];

        if (!empty($filterList)) {
            $cmd = array_merge($cmd, $filterList);
        }

        $output = $this->device->shell->run($cmd);

        if (preg_match_all('/^package:(\S+)\r?$/m', $output, $matches)) {
            $result = $matches[1];
        }

        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * Get the currently focused (foreground) app on the device.
     *
     * Returns an array with keys:
     *   - package: string, package name of the current app
     *   - activity: string, current activity name
     *   - pid: int, process id (0 if unavailable)
     *
     * @return array
     * @throws AdbException If unable to determine the current app
     */
    public function currentFocus(): array
    {
        // First try: dumpsys window windows -> mCurrentFocus
        $output = $this->device->shell->run(['dumpsys', 'window', 'windows']);
        if (preg_match('/mCurrentFocus=Window{.*\s(\S+)\/(\S+)}/', $output, $m)) {
            return [
                'package' => $m[1],
                'activity' => $m[2],
                'pid' => 0,
            ];
        }

        // Second try: dumpsys activity activities -> mResumedActivity
        $output2 = $this->device->shell->run(['dumpsys', 'activity', 'activities']);
        $package = null;
        if (preg_match('/mResumedActivity: ActivityRecord{.*\s(\S+)\/(\S+)\s.*}/', $output2, $m)) {
            $package = $m[1];
        }

        // Third try: dumpsys activity top -> ACTIVITY ... pid=...
        $output3 = $this->device->shell->run(['dumpsys', 'activity', 'top']);
        if (preg_match_all('/ACTIVITY (\S+)\/(\S+) \w+ pid=(\d+)/', $output3, $matches, PREG_SET_ORDER)) {
            $last = null;
            foreach ($matches as $m) {
                $last = [
                    'package' => $m[1],
                    'activity' => $m[2],
                    'pid' => (int)$m[3],
                ];
                if ($package && $last['package'] === $package) {
                    return $last;
                }
            }
            if ($last) {
                return $last;
            }
        }

        throw new AdbException("Couldn't get focused app");
    }
}