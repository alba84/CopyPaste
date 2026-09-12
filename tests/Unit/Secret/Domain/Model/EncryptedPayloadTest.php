<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Domain\Model;

use App\Secret\Domain\Exception\InvalidEncryptedPayload;
use App\Secret\Domain\Model\EncryptedPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EncryptedPayloadTest extends TestCase
{
    public function testAcceptsValidParts(): void
    {
        $payload = new EncryptedPayload(str_repeat('s', 16), str_repeat('n', 24), 'ciphertext');

        self::assertSame(str_repeat('s', 16), $payload->salt);
        self::assertSame(str_repeat('n', 24), $payload->nonce);
        self::assertSame('ciphertext', $payload->ciphertext);
    }

    public function testDomainByteLengthsMatchSodium(): void
    {
        self::assertSame(SODIUM_CRYPTO_PWHASH_SALTBYTES, EncryptedPayload::SALT_BYTES);
        self::assertSame(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, EncryptedPayload::NONCE_BYTES);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'short salt' => [str_repeat('s', 15), str_repeat('n', 24), 'ciphertext'];
        yield 'long salt' => [str_repeat('s', 17), str_repeat('n', 24), 'ciphertext'];
        yield 'short nonce' => [str_repeat('s', 16), str_repeat('n', 23), 'ciphertext'];
        yield 'long nonce' => [str_repeat('s', 16), str_repeat('n', 25), 'ciphertext'];
        yield 'empty ciphertext' => [str_repeat('s', 16), str_repeat('n', 24), ''];
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidParts(string $salt, string $nonce, string $ciphertext): void
    {
        $this->expectException(InvalidEncryptedPayload::class);

        new EncryptedPayload($salt, $nonce, $ciphertext);
    }
}
