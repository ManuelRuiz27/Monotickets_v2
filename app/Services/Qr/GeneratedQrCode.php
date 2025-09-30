<?php

namespace App\Services\Qr;

final class GeneratedQrCode
{
    public function __construct(
        public readonly string $payload,
        public readonly string $displayCode,
    ) {
    }
}
