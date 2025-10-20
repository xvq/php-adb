# PHP ADB Library

A PHP library for interacting with Android devices via ADB.

# Requires
* PHP 8.1 or higher
* ext-mbstring

# Installation
```bash
composer require xvq/php-adb
```

# Usage
## Connect ADB Server
```php
use Xvq\PhpAdb\AdbClient;

$adb = new AdbClient('127.0.0.1',5037);
```
## List all the devices and get device object
```php
$devices = $adb->getAllDevices();
$device = $devices[0];

$serials = $adb->getAllDeviceSerials();

//serial
$device = $adb->device($serials[0]);
$device = $adb->device('8e257561');

//transportId
$device = $adb->device(transportId: 2);

//when there is only one device, no arguments need to be provided.
$device = $adb->device();
```
## Connect or disconnect remote device
```php
$output = $adb->connect("192.168.1.10:5555");
// output: connected to 192.168.1.10:5555
$output = $adb->disconnect("192.168.1.10:5555");
// output: disconnected from 192.168.1.10:5555
```
## adb forward and adb reverse
```php
$device->forward('tcp:6666', 'tcp:5555');

$result = $device->forwardList();
var_dump($result);

/*
 * array(
 *     [
 *         "serial" => "9e224311",
 *         "local"  => "tcp:6666",
 *         "remote" => "tcp:5555"
 *     ]
 * )
 */

$device->reverse('tcp:5555', 'tcp:6666');
$device->reverseList();
```
## Create socket connection to the device

```php
use Xvq\PhpAdb\Enum\Network;

// Example: create a video stream connection for scrcpy
$con = $device->createConnection(Network::LOCAL_ABSTRACT, 'scrcpy');
foreach ($con->readStream() as $chunk){
    //...
}

$con = $device->createConnection(Network::TCP, 8000);
$con->read(500);
```
## Run shell command
```php
# Argument support array, string
$output = $device->shell->run(["getprop", "ro.serial"]);
// or
$output = $device->shell->run("getprop ro.serial");

// set timeout
$output = $device->shell->run("getprop ro.serial", timeout: 5);

// stream output
foreach ($device->shell->run("cat /sdcard/test.txt", stream: true) as $chunk){
    // ...
}

//use shell v2 protocol
$r = $device->shell->runV2("getprop ro.serial");
$command = $r->command;
$returnCode = $r->returnCode;
$output = $r->output;
$stdout = $r->stdout;
$stderr = $r->stderr;

$device->shell->getProp('ro.serial');
$device->shell->switchAirplane(true);
$device->shell->switchWifi(true);
$device->shell->getWindowSize();
// ...
```
## Take screenshot
```php
$device->screenshot->save('screenshot.png');

$raw = $device->screenshot->raw();
file_put_contents('screenshot.png', $raw);
```
## File Operations
```php
// push file
$device->file->push('test.txt', '/sdcard/test.txt');
// pull file
$device->file->pull('/sdcard/test.txt', 'test.txt');

// read file
foreach ($device->file->read('/sdcard/test.txt') as $chunk){
    // ...
};

// list directory
foreach ($device->file->listDirectory('/sdcard/') as $file){
    echo $file['name'];
    echo $file['size'];
    echo $file['mode'];
    echo $file['mtime'];
}

// stat
$stat = $device->file->stat('/sdcard/test.txt');
echo $stat['name'];
echo $stat['size'];
echo $stat['mode'];
echo $stat['mtime'];

$device->file->copy('/sdcard/test.txt', '/sdcard/test2.txt');
$device->file->move('/sdcard/test2.txt', '/sdcard/test.txt');
$device->file->remove('/sdcard/test.txt');
$device->file->mkdir('/sdcard/test');
```
## APP Operations
```php
// Push and install an APK from the local filesystem to the device.
// The file './test.apk' will be uploaded to the device and then installed.
$result = $device->app->pushAndInstall('./test.apk');

// Install an APK that already exists on the device's storage.
$result = $device->app->install('/sdcard/test2.apk');
// $result 1 = success,  2 = need manual confirmation

$device->app->start('com.example.test');

// Get the current activity that is in focus.
$result = $device->app->currentFocus();
echo $result['package'];
echo $result['activity'];
echo $result['pid'];

$device->app->stop('com.example.test');
$device->app->clear('com.example.test');
$device->app->uninstall('com.example.test');

foreach ($device->app->list() as $packageName){
    echo $packageName;
}

$info = $device->app->info('com.example.test');
// $info is an associative array containing details such as:
[
    'package_name'       => 'com.example.test',
    'version_name'       => '1.0.0',
    'version_code'       => 100,
    'flags'              => 'SYSTEM',
    'first_install_time' => '2024-05-01 10:30:00',
    'last_update_time'   => '2024-05-10 15:42:00',
    'signature'          => 'AB:CD:EF:...',
    'path'               => '/data/app/com.example.test/base.apk',
    'sub_apk_paths'      => [],
]
```
## Input

```php
use Xvq\PhpAdb\Enum\KeyCode;

// Simulate pressing the Home button
$device->input->keyEvent(KeyCode::KEY_HOME);

// Simulate typing text
$device->input->sendText('Hello World');

// Simulate tapping at (100px, 100px)
$device->input->tap(100, 100);

// Simulate tapping the screen center (50%, 50%) — decimals represent screen percentage
$device->input->tap(0.5, 0.5);

// Simulate swipe from (100px,100px) → (200px,200px) in 0.5 seconds
$device->input->swipe(100, 100, 200, 200, 0.5);

// Simulate swipe from (10%,50%) → (90%,50%) in 0.5 seconds — decimals represent screen percentage
$device->input->swipe(0.1, 0.5, 0.9, 0.5, 0.5);

// Simulate drag from (100px,100px) → (200px,200px) in 0.5 seconds
$device->input->drag(100, 100, 200, 200, 0.5);

// Simulate mouse wheel scroll down by one unit
$device->input->roll(0, 1);

// Simulate mouse wheel scroll up by one unit
$device->input->roll(0, -1);
```
## Other
```php
$device->reboot();

// get device state ,[device,offline,bootloader]
$device->getState();

$v = $device->androidVersion();

// get device info
$info = $device->info;
echo $info->serial;
echo $info->transportId;
echo $info->product;
echo $info->model;
echo $info->device;

//adb root
$device->root();

// get device features
$device->getFeatures();

// open browser
$device->shell->openBrowser("https://www.baidu.com");

// is screen on
$device->shell->isScreenOn();

// get window size
$arr = $device->shell->getWindowSize();
echo $arr['width'];
echo $arr['height'];

// There are many other available methods as well
// please refer to the source code for details.
```
# Thanks
This project is developed with reference to [openatx/adbutils](https://github.com/openatx/adbutils).
# License
[MIT](LICENSE)