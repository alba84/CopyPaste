<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crypto\Domain;

use App\Crypto\Domain\DecryptedPlaintext;
use App\Crypto\Domain\DecryptionFailed;
use App\Crypto\Domain\DecryptionResult;
use PHPUnit\Framework\TestCase;

final class DecryptionResultTest extends TestCase
{
    public function testDecryptedPlaintextCarriesRawString(): void
    {
        $result = new DecryptedPlaintext('Полученный текст');

        self::assertInstanceOf(DecryptionResult::class, $result);
        self::assertSame('Полученный текст', $result->plaintext);
    }

    public function testDecryptedPlaintextAllowsEmptyString(): void
    {
        $result = new DecryptedPlaintext('');

        self::assertSame('', $result->plaintext);
    }

    public function testDecryptionFailedRepresentsInvalidKey(): void
    {
        $result = DecryptionFailed::invalidKey();

        self::assertInstanceOf(DecryptionResult::class, $result);
        self::assertInstanceOf(DecryptionFailed::class, $result);
    }
}
