<?php

declare(strict_types=1);

namespace App\Tests\Support\Secret;

use App\Crypto\Domain\Cryptography;
use App\Crypto\Domain\DecryptionFailed;
use App\Crypto\Domain\DecryptionResult;
use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Plaintext;
use App\Secret\Domain\Model\SecretKey;

final class RecordingCryptography implements Cryptography
{
    /** @var list<array{plaintext: Plaintext, secret: SecretKey}> */
    public array $encryptions = [];

    public function __construct(private readonly EncryptedPayload $payload)
    {
    }

    public function encrypt(Plaintext $plaintext, SecretKey $secret): EncryptedPayload
    {
        $this->encryptions[] = ['plaintext' => $plaintext, 'secret' => $secret];

        return $this->payload;
    }

    public function decrypt(EncryptedPayload $payload, SecretKey $secret): DecryptionResult
    {
        return DecryptionFailed::invalidKey();
    }
}
