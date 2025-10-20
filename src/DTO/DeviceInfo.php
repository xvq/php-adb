<?php

namespace Xvq\PhpAdb\DTO;

class DeviceInfo
{
    public function __construct(
        public readonly string $serial = '',
        public readonly int    $transportId = 0,
        public readonly string $product = '',
        public readonly string $model = '',
        public readonly string $device = '',
    ) {}

    public function toArray(): array
    {
        return [
            'serial' => $this->serial,
            'transportId' => $this->transportId,
            'product' => $this->product,
            'model' => $this->model,
            'device' => $this->device,
        ];
    }
}