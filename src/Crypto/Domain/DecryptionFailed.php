<?php

declare(strict_types=1);

namespace App\Crypto\Domain;

final readonly class DecryptionFailed implements DecryptionResult
{
    private function __construct()
    {
    }

    public static function invalidKey(): self
    {
        return new self();
    }
}
