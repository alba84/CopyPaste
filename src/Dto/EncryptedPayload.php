<?php

namespace App\Dto;

final readonly class EncryptedPayload
{
    public function __construct(
        public string $salt,
        public string $nonce,
        public string $ciphertext,
    ) {
    }
}
