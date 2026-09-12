<?php

declare(strict_types=1);

namespace App\Crypto\Domain;

final readonly class DecryptedPlaintext implements DecryptionResult
{
    public function __construct(public string $plaintext)
    {
    }
}
