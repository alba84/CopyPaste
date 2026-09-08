<?php

namespace App\Service;

use App\Dto\EncryptedPayload;

final class EncryptionService
{
    public function __construct(
        private readonly int $configuredOpsLimit,
        private readonly int $configuredMemLimit,
    ) {
    }

    public function encrypt(string $plaintext, string $secret): EncryptedPayload
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->deriveKey($secret, $salt);

        try {
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        } finally {
            sodium_memzero($key);
        }

        return new EncryptedPayload($salt, $nonce, $ciphertext);
    }

    public function decrypt(EncryptedPayload $payload, string $secret): ?string
    {
        $key = $this->deriveKey($secret, $payload->salt);

        try {
            $plaintext = sodium_crypto_secretbox_open($payload->ciphertext, $payload->nonce, $key);

            return false === $plaintext ? null : $plaintext;
        } finally {
            sodium_memzero($key);
        }
    }

    private function deriveKey(string $secret, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $secret,
            $salt,
            $this->configuredOpsLimit,
            $this->configuredMemLimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }
}
