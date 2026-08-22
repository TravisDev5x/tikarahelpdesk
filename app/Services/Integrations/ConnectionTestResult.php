<?php

namespace App\Services\Integrations;

class ConnectionTestResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
    ) {}

    public static function success(string $message): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
