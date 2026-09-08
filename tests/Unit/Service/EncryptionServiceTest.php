<?php

namespace App\Tests\Unit\Service;

use App\Service\EncryptionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EncryptionServiceTest extends TestCase
{
    private EncryptionService $service;

    protected function setUp(): void
    {
        $this->service = new EncryptionService(SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
    }

    /** @return iterable<string, array{string}> */
    public static function plaintexts(): iterable
    {
        yield 'empty' => [''];
        yield 'unicode' => ['Привет, мир! 🔐'];
        yield 'large' => [str_repeat('abc', 40000)];
    }

    #[DataProvider('plaintexts')]
    public function testRoundTrip(string $plaintext): void
    {
        $payload = $this->service->encrypt($plaintext, 'correct horse');
        self::assertSame($plaintext, $this->service->decrypt($payload, 'correct horse'));
    }

    public function testWrongKeyReturnsNull(): void
    {
        $payload = $this->service->encrypt('secret', 'right');
        self::assertNull($this->service->decrypt($payload, 'wrong'));
    }

    public function testSaltAndNonceAreUnique(): void
    {
        $one = $this->service->encrypt('same', 'same');
        $two = $this->service->encrypt('same', 'same');
        self::assertNotSame($one->salt, $two->salt);
        self::assertNotSame($one->nonce, $two->nonce);
    }
}
