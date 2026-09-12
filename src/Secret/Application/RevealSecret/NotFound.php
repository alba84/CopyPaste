<?php

declare(strict_types=1);

namespace App\Secret\Application\RevealSecret;

final readonly class NotFound implements RevealSecretResult
{
    private function __construct()
    {
    }

    public static function notFound(): self
    {
        return new self();
    }
}
