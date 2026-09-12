<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crypto\Domain;

use App\Crypto\Domain\DecryptedPlaintext;
use App\Crypto\Domain\DecryptionFailed;
use App\Crypto\Domain\DecryptionResult;
use App\Secret\Domain\Model\Plaintext;
use PHPUnit\Framework\TestCase;

final class DecryptionResultTest extends TestCase
{
    public function testDecryptedPlaintextCarriesPlaintext(): void
    {
        $plaintext = Plaintext::fromString('Полученный текст');
        $result = new DecryptedPlaintext($plaintext);

        self::assertInstanceOf(DecryptionResult::class, $result);
        self::assertSame($plaintext, $result->plaintext);
    }

    public function testDecryptionFailedRepresentsInvalidKey(): void
    {
        $result = DecryptionFailed::invalidKey();

        self::assertInstanceOf(DecryptionResult::class, $result);
        self::assertInstanceOf(DecryptionFailed::class, $result);
    }
}
