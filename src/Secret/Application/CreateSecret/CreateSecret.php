<?php

declare(strict_types=1);

namespace App\Secret\Application\CreateSecret;

final readonly class CreateSecret
{
    public function __construct(
        public string $plaintext,
        public ?string $hint,
        public string $secretKey,
    ) {
    }
}
