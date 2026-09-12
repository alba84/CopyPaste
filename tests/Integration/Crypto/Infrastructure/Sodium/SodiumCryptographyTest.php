<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crypto\Infrastructure\Sodium;

use App\Crypto\Domain\DecryptedPlaintext;
use App\Crypto\Domain\DecryptionFailed;
use App\Crypto\Infrastructure\Sodium\SodiumCryptography;
use App\Dto\EncryptedPayload as LegacyEncryptedPayload;
use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Plaintext;
use App\Secret\Domain\Model\SecretKey;
use App\Service\EncryptionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SodiumCryptographyTest extends TestCase
{
    private const string SECRET_KEY = 'correct horse battery staple';

    /** @phpstan-ignore property.uninitialized (initialized in setUp(); review 2027-06-30) */
    private SodiumCryptography $cryptography;
    /** @phpstan-ignore property.uninitialized (initialized in setUp(); review 2027-06-30) */
    private EncryptionService $legacyEncryption;

    protected function setUp(): void
    {
        $opsLimit = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $memLimit = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;

        $this->cryptography = new SodiumCryptography($opsLimit, $memLimit);
        $this->legacyEncryption = new EncryptionService($opsLimit, $memLimit);
    }

    /** @return iterable<string, array{string}> */
    public static function plaintexts(): iterable
    {
        yield 'ascii' => ['hello world'];
        yield 'unicode' => ['Привет, мир! 🔐'];
        yield 'large' => [str_repeat('abc', 33333)];
    }

    #[DataProvider('plaintexts')]
    public function testEncryptThenDecryptReturnsOriginalPlaintext(string $plaintext): void
    {
        $payload = $this->cryptography->encrypt(Plaintext::fromString($plaintext), SecretKey::fromString(self::SECRET_KEY));

        $result = $this->cryptography->decrypt($payload, SecretKey::fromString(self::SECRET_KEY));

        self::assertInstanceOf(DecryptedPlaintext::class, $result);
        self::assertSame($plaintext, $result->plaintext);
    }

    public function testEncryptUsesStandardSaltAndNonceLengths(): void
    {
        $payload = $this->cryptography->encrypt(Plaintext::fromString('secret'), SecretKey::fromString(self::SECRET_KEY));

        self::assertSame(SODIUM_CRYPTO_PWHASH_SALTBYTES, strlen($payload->salt));
        self::assertSame(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, strlen($payload->nonce));
        self::assertNotSame('', $payload->ciphertext);
    }

    public function testWrongKeyYieldsDecryptionFailed(): void
    {
        $payload = $this->cryptography->encrypt(Plaintext::fromString('secret'), SecretKey::fromString(self::SECRET_KEY));

        $result = $this->cryptography->decrypt($payload, SecretKey::fromString('wrong key'));

        self::assertInstanceOf(DecryptionFailed::class, $result);
    }

    public function testSaltAndNonceAreUniquePerEncryption(): void
    {
        $first = $this->cryptography->encrypt(Plaintext::fromString('same'), SecretKey::fromString(self::SECRET_KEY));
        $second = $this->cryptography->encrypt(Plaintext::fromString('same'), SecretKey::fromString(self::SECRET_KEY));

        self::assertNotSame($first->salt, $second->salt);
        self::assertNotSame($first->nonce, $second->nonce);
    }

    public function testNewEncryptionIsDecryptableByLegacyService(): void
    {
        $plaintext = 'интероп новый → легаси';
        $payload = $this->cryptography->encrypt(Plaintext::fromString($plaintext), SecretKey::fromString(self::SECRET_KEY));
        $legacyPayload = new LegacyEncryptedPayload($payload->salt, $payload->nonce, $payload->ciphertext);

        self::assertSame($plaintext, $this->legacyEncryption->decrypt($legacyPayload, self::SECRET_KEY));
    }

    public function testLegacyEncryptionIsDecryptableByNewAdapter(): void
    {
        $plaintext = 'интероп легаси → новый';
        $legacyPayload = $this->legacyEncryption->encrypt($plaintext, self::SECRET_KEY);
        $payload = new EncryptedPayload($legacyPayload->salt, $legacyPayload->nonce, $legacyPayload->ciphertext);

        $result = $this->cryptography->decrypt($payload, SecretKey::fromString(self::SECRET_KEY));

        self::assertInstanceOf(DecryptedPlaintext::class, $result);
        self::assertSame($plaintext, $result->plaintext);
    }

    public function testEmptyPlaintextEncryptedByLegacyServiceIsDecryptedAsSuccess(): void
    {
        $legacyPayload = $this->legacyEncryption->encrypt('', self::SECRET_KEY);
        $payload = new EncryptedPayload($legacyPayload->salt, $legacyPayload->nonce, $legacyPayload->ciphertext);

        $result = $this->cryptography->decrypt($payload, SecretKey::fromString(self::SECRET_KEY));

        self::assertInstanceOf(DecryptedPlaintext::class, $result);
        self::assertSame('', $result->plaintext);
    }
}
