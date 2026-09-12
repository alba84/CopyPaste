<?php

declare(strict_types=1);

namespace App\Secret\Application\RevealSecret;

final readonly class RevealSecret
{
    public function __construct(
        public string $token,
        public string $secretKey,
    ) {
    }
}
