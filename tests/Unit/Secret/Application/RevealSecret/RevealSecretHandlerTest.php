<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Application\RevealSecret;

use App\Crypto\Domain\DecryptedPlaintext;
use App\Secret\Application\RevealSecret\InvalidKey;
use App\Secret\Application\RevealSecret\NotFound;
use App\Secret\Application\RevealSecret\Revealed;
use App\Secret\Application\RevealSecret\RevealSecret;
use App\Secret\Application\RevealSecret\RevealSecretHandler;
use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Hint;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\Token;
use App\Tests\Support\Secret\FixedClock;
use App\Tests\Support\Secret\InMemorySecretRepository;
use App\Tests\Support\Secret\RecordingCryptography;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RevealSecretHandlerTest extends TestCase
{
    private const string TOKEN = '0123456789abcdef0123456789abcdef';
    private const string KEY = 'correct key';
    private const string PLAINTEXT = 'Секретный текст';
    private const string NOW = '2026-03-01 10:00:00';

    public function testRevealsPlaintextAndPassesPayloadAndKeyToCryptography(): void
    {
        $secret = $this->secret(new DateTimeImmutable(self::NOW.'+1 second'));
        $repository = $this->repositoryWith($secret);
        $cryptography = new RecordingCryptography($this->payload(), new DecryptedPlaintext(self::PLAINTEXT));

        $result = $this->handler($repository, $cryptography)(new RevealSecret(self::TOKEN, self::KEY));

        self::assertInstanceOf(Revealed::class, $result);
        self::assertSame(self::PLAINTEXT, $result->plaintext);
        self::assertCount(1, $cryptography->decryptions);
        self::assertSame($secret->payload(), $cryptography->decryptions[0]['payload']);
        self::assertSame(self::KEY, $cryptography->decryptions[0]['secret']->value);
    }

    public function testEmptyPlaintextIsRevealedAsSuccess(): void
    {
        $repository = $this->repositoryWith($this->secret(new DateTimeImmutable(self::NOW.'+1 second')));
        $cryptography = new RecordingCryptography($this->payload(), new DecryptedPlaintext(''));

        $result = $this->handler($repository, $cryptography)(new RevealSecret(self::TOKEN, self::KEY));

        self::assertInstanceOf(Revealed::class, $result);
        self::assertSame('', $result->plaintext);
    }

    public function testInvalidKeyYieldsInvalidKeyResultWithoutException(): void
    {
        $repository = $this->repositoryWith($this->secret(new DateTimeImmutable(self::NOW.'+1 second')));
        $cryptography = new RecordingCryptography($this->payload());

        $result = $this->handler($repository, $cryptography)(new RevealSecret(self::TOKEN, 'wrong key'));

        self::assertInstanceOf(InvalidKey::class, $result);
        self::assertCount(1, $cryptography->decryptions);
    }

    public function testMissingSecretYieldsNotFoundWithoutDecryption(): void
    {
        $cryptography = new RecordingCryptography($this->payload(), new DecryptedPlaintext(self::PLAINTEXT));

        $result = $this->handler(new InMemorySecretRepository(), $cryptography)(new RevealSecret(self::TOKEN, self::KEY));

        self::assertInstanceOf(NotFound::class, $result);
        self::assertCount(0, $cryptography->decryptions);
    }

    /** @return iterable<string, array{string}> */
    public static function expiredMoments(): iterable
    {
        yield 'before now' => ['2026-03-01 09:59:59'];
        yield 'exactly now' => [self::NOW];
    }

    #[DataProvider('expiredMoments')]
    public function testExpiredSecretYieldsNotFoundWithoutDecryption(string $expiresAt): void
    {
        $repository = $this->repositoryWith($this->secret(new DateTimeImmutable($expiresAt)));
        $cryptography = new RecordingCryptography($this->payload(), new DecryptedPlaintext(self::PLAINTEXT));

        $result = $this->handler($repository, $cryptography)(new RevealSecret(self::TOKEN, self::KEY));

        self::assertInstanceOf(NotFound::class, $result);
        self::assertCount(0, $cryptography->decryptions);
    }

    public function testActiveSecretAfterExpirationMomentIsDecrypted(): void
    {
        $repository = $this->repositoryWith($this->secret(new DateTimeImmutable('2026-03-01 10:00:01')));
        $cryptography = new RecordingCryptography($this->payload(), new DecryptedPlaintext(self::PLAINTEXT));

        $result = $this->handler($repository, $cryptography)(new RevealSecret(self::TOKEN, self::KEY));

        self::assertInstanceOf(Revealed::class, $result);
        self::assertCount(1, $cryptography->decryptions);
    }

    private function handler(InMemorySecretRepository $repository, RecordingCryptography $cryptography): RevealSecretHandler
    {
        return new RevealSecretHandler($repository, $cryptography, new FixedClock(new DateTimeImmutable(self::NOW)));
    }

    private function repositoryWith(Secret $secret): InMemorySecretRepository
    {
        $repository = new InMemorySecretRepository();
        $repository->save($secret);

        return $repository;
    }

    private function secret(DateTimeImmutable $expiresAt): Secret
    {
        return Secret::reconstitute(
            new Token(self::TOKEN),
            Hint::fromUserInput('подсказка'),
            $this->payload(),
            new DateTimeImmutable('2026-02-01 10:00:00'),
            $expiresAt,
        );
    }

    private function payload(): EncryptedPayload
    {
        return new EncryptedPayload(str_repeat('s', 16), str_repeat('n', 24), 'ciphertext');
    }
}
