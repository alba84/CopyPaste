<?php

declare(strict_types=1);

namespace App\Crypto\Domain;

use App\Secret\Domain\Model\Plaintext;

final readonly class DecryptedPlaintext implements DecryptionResult
{
    public function __construct(public Plaintext $plaintext)
    {
    }
}
