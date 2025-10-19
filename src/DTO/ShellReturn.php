<?php

namespace Xvq\PhpAdb\DTO;

class ShellReturn
{
    public function __construct(
        public readonly string $command,
        public readonly int    $returnCode,
        public readonly string $output,
        public readonly string $stdout,
        public readonly string $stderr
    ) {}

    public function toArray(): array
    {
        return [
            'command' => $this->command,
            'returnCode' => $this->returnCode,
            'output' => $this->output,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr
        ];
    }
}