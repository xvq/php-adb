<?php

namespace Xvq\PhpAdb\Extension;

use Xvq\PhpAdb\Device;
use Xvq\PhpAdb\Enum\KeyCode;
use Xvq\PhpAdb\Exception\AdbException;

class Input
{
    public function __construct(
        private readonly Device $device
    ){}

    /**
     * Send a key event to the device.
     *
     * @param KeyCode|int $keyCode The key code to send (KeyCode enum or integer)
     */
    public function keyEvent(KeyCode|int $keyCode): void
    {
        $keyCode = $keyCode instanceof KeyCode ? $keyCode->value : $keyCode;
        $this->device->shell->run('input keyevent ' . $keyCode);
    }

    /**
     * Swipe from start point to end point
     *
     * @param float|int $sx Start X position (absolute or percentage)
     * @param float|int $sy Start Y position (absolute or percentage)
     * @param float|int $ex End X position (absolute or percentage)
     * @param float|int $ey End Y position (absolute or percentage)
     * @param float|int $duration Duration in seconds (default: 1)
     */
    public function swipe(float|int $sx, float|int $sy, float|int $ex, float|int $ey, float|int $duration = 1): void
    {
        $this->touch('swipe',$sx, $sy, $ex, $ey, $duration);
    }

    /**
     * drag and drop from start point to end point
     *
     * @param float|int $sx Start X position (absolute or percentage)
     * @param float|int $sy Start Y position (absolute or percentage)
     * @param float|int $ex End X position (absolute or percentage)
     * @param float|int $ey End Y position (absolute or percentage)
     * @param float|int $duration Duration in seconds (default: 1)
     */
    public function drag(float|int $sx, float|int $sy, float|int $ex, float|int $ey, float|int $duration = 1): void
    {
        $this->touch('draganddrop',$sx, $sy, $ex, $ey, $duration);
    }

    /**
     * Execute a touch-related input command on the device.
     *
     * @param string $cmd       The input command (e.g. "swipe", "tap")
     * @param float|int $sx     Start X position (absolute or percentage)
     * @param float|int $sy     Start Y position (absolute or percentage)
     * @param float|int $ex     End X position (absolute or percentage)
     * @param float|int $ey     End Y position (absolute or percentage)
     * @param float|int $duration Duration in seconds (default: 1)
     */
    private function touch(string $cmd,float|int $sx, float|int $sy, float|int $ex, float|int $ey, float|int $duration = 1): void
    {
        $points = [$sx, $sy, $ex, $ey];

        $isPercent = fn($v) => is_float($v) && $v > 0 && $v <= 1;

        if (array_filter($points, $isPercent)) {
            $size = $this->device->shell->getWindowSize();
            $w = $size['width'];
            $h = $size['height'];

            $sx = $isPercent($sx) ? (int)($sx * $w) : $sx;
            $sy = $isPercent($sy) ? (int)($sy * $h) : $sy;
            $ex = $isPercent($ex) ? (int)($ex * $w) : $ex;
            $ey = $isPercent($ey) ? (int)($ey * $h) : $ey;
        }

        $this->device->shell->run([
            'input',$cmd,
            $sx, $sy,
            $ex, $ey,
            $duration * 1000
        ]);
    }

    /**
     * Simulate a tap on the device screen.
     *
     * Coordinates can be absolute pixels or normalized percentages (0.0 - 1.0).
     *
     * @param float|int $x X position (absolute or percentage)
     * @param float|int $y Y position (absolute or percentage)
     */
    public function tap(float|int $x, float|int $y): void
    {
        $isPercent = fn($v) => is_float($v) && $v > 0 && $v <= 1;

        if ($isPercent($x) || $isPercent($y)) {
            $size = $this->device->shell->getWindowSize();
            $x = $isPercent($x) ? (int)($x * $size['width']) : $x;
            $y = $isPercent($y) ? (int)($y * $size['height']) : $y;
        }

        $this->device->shell->run(['input','tap',$x,$y]);
    }

    /**
     * Simulate a scroll/roll input event on the device.
     *
     * @param int $dx Horizontal scroll amount (positive = right, negative = left)
     * @param int $dy Vertical scroll amount (positive = down, negative = up)
     */
    public function roll(int $dx = 0, int $dy = 0): void
    {
        $this->device->shell->run([
            'input', 'roll',
            $dx, $dy,
        ]);
    }

    /**
     * Type a given text on the device.
     *
     * @param string $text Text to be typed
     * @throws AdbException
     */
    public function sendText(string $text): void
    {
        $escapedText = str_replace(' ', '%s', $text);

        $this->device->shell->run(['input', 'text', $escapedText]);
    }
}