<?php

declare(strict_types=1);

namespace App\Crypto\Infrastructure\Sodium;

use App\Crypto\Domain\Cryptography;
use App\Crypto\Domain\DecryptedPlaintext;
use App\Crypto\Domain\DecryptionFailed;
use App\Crypto\Domain\DecryptionResult;
use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Plaintext;
use App\Secret\Domain\Model\SecretKey;

final class SodiumCryptography implements Cryptography
{
    public function __construct(
        private readonly int $configuredOpsLimit,
        private readonly int $configuredMemLimit,
    ) {
    }

    public function encrypt(Plaintext $plaintext, SecretKey $secret): EncryptedPayload
    {
        $salt = random_bytes(EncryptedPayload::SALT_BYTES);
        $nonce = random_bytes(EncryptedPayload::NONCE_BYTES);
        $key = $this->deriveKey($secret->value, $salt);

        try {
            $ciphertext = sodium_crypto_secretbox($plaintext->value, $nonce, $key);
        } finally {
            sodium_memzero($key);
        }

        return new EncryptedPayload($salt, $nonce, $ciphertext);
    }

    public function decrypt(EncryptedPayload $payload, SecretKey $secret): DecryptionResult
    {
        $key = $this->deriveKey($secret->value, $payload->salt);

        try {
            $plaintext = sodium_crypto_secretbox_open($payload->ciphertext, $payload->nonce, $key);
        } finally {
            sodium_memzero($key);
        }

        if (false === $plaintext) {
            return DecryptionFailed::invalidKey();
        }

        return new DecryptedPlaintext(Plaintext::fromString($plaintext));
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
