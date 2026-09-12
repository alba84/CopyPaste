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

    /** @var list<array{payload: EncryptedPayload, secret: SecretKey}> */
    public array $decryptions = [];

    public function __construct(
        private readonly EncryptedPayload $payload,
        private readonly ?DecryptionResult $decryptionResult = null,
    ) {
    }

    public function encrypt(Plaintext $plaintext, SecretKey $secret): EncryptedPayload
    {
        $this->encryptions[] = ['plaintext' => $plaintext, 'secret' => $secret];

        return $this->payload;
    }

    public function decrypt(EncryptedPayload $payload, SecretKey $secret): DecryptionResult
    {
        $this->decryptions[] = ['payload' => $payload, 'secret' => $secret];

        return $this->decryptionResult ?? DecryptionFailed::invalidKey();
    }
}
