<?php

declare(strict_types=1);

namespace App\Secret\Domain\Model;

use App\Secret\Domain\Exception\InvalidEncryptedPayload;

final readonly class EncryptedPayload
{
    public const int SALT_BYTES = 16;
    public const int NONCE_BYTES = 24;

    public function __construct(
        public string $salt,
        public string $nonce,
        public string $ciphertext,
    ) {
        if (self::SALT_BYTES !== strlen($salt)) {
            throw new InvalidEncryptedPayload('Encrypted payload salt must be 16 bytes.');
        }

        if (self::NONCE_BYTES !== strlen($nonce)) {
            throw new InvalidEncryptedPayload('Encrypted payload nonce must be 24 bytes.');
        }

        if ('' === $ciphertext) {
            throw new InvalidEncryptedPayload('Encrypted payload ciphertext must not be empty.');
        }
    }
}
