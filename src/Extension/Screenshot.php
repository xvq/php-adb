<?php

namespace Xvq\PhpAdb\Extension;

use Xvq\PhpAdb\Device;
use Xvq\PhpAdb\Exception\AdbException;

class Screenshot
{
    public function __construct(
        private readonly Device $device
    ){}

    /**
     * Capture a screenshot and return the raw image data.
     *
     * @param int|null $displayId Optional logical display ID
     * @return string Screenshot image data
     */
    public function raw(?int $displayId = null) : string
    {
        return $this->screenshot('', $displayId);
    }

    /**
     * Capture a screenshot and save it to a file.
     *
     * @param string $savePath    Path to save the screenshot file
     * @param int|null $displayId Optional logical display ID
     * @return bool
     */
    public function save(string $savePath = '', ?int $displayId = null) : bool
    {
        return $this->screenshot($savePath, $displayId);
    }

    /**
     * Capture a screenshot from the device.
     *
     * @param string $savePath    Optional path to save the screenshot file.
     *                            If empty, returns the raw screenshot data as a string.
     * @param int|null $displayId Optional logical display ID to capture.
     * @return string|bool        Raw screenshot data if $savePath is empty,
     *                            or true/false indicating success of file save.
     * @throws AdbException       If the screenshot could not be captured.
     */
    private function screenshot(string $savePath = '', ?int $displayId = null) : string|bool
    {
        $cmd = ['screencap','-p'];

        if($displayId){
            $cmd[] = '-d';
            $cmd[] = $this->getAllDisplayIds()[$displayId];
        }

        $output = $this->device->shell->run($cmd);
        if(empty($output)){
            throw new AdbException('Get screenshot failed!');
        }

        return $savePath ? (bool)file_put_contents($savePath, $output) : $output;
    }

    /**
     * Retrieve all real (SurfaceFlinger) display IDs from the device.
     *
     * @return string[] Array of display IDs (as strings, e.g. ["4619827259835644672", ...])
     * @throws AdbException If no display IDs are found in the output
     */
    private function getAllDisplayIds(): array
    {
        $output = $this->device->shell->run('dumpsys SurfaceFlinger --display-id');
        preg_match_all('/Display (\d+) /', $output, $matches);
        $ids = $matches[1] ?? [];
        if (empty($ids)) {
            throw new AdbException("No display found, debug with 'dumpsys SurfaceFlinger --display-id'");
        }

        return $ids;
    }
}