<?php

declare(strict_types=1);

namespace App\Secret\Infrastructure\Persistence\Doctrine;

use DateTimeImmutable;

final readonly class SecretRecord
{
    public function __construct(
        public string $token,
        public ?string $hint,
        public string $salt,
        public string $nonce,
        public string $ciphertext,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
