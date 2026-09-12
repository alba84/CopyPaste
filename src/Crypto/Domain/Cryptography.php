<?php

declare(strict_types=1);

namespace App\Crypto\Domain;

use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Plaintext;
use App\Secret\Domain\Model\SecretKey;

interface Cryptography
{
    public function encrypt(Plaintext $plaintext, SecretKey $secret): EncryptedPayload;

    public function decrypt(EncryptedPayload $payload, SecretKey $secret): DecryptionResult;
}
