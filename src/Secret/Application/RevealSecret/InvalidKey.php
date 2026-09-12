<?php

declare(strict_types=1);

namespace App\Secret\Application\RevealSecret;

final readonly class InvalidKey implements RevealSecretResult
{
    private function __construct()
    {
    }

    public static function invalidKey(): self
    {
        return new self();
    }
}
