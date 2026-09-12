<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Application\CreateSecret;

use App\Secret\Application\CreateSecret\CreateSecret;
use App\Secret\Application\CreateSecret\CreateSecretHandler;
use App\Secret\Domain\Event\SecretCreated;
use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Model\Ttl;
use App\Secret\Domain\Service\UniqueTokenGenerator;
use App\Tests\Support\Secret\FixedClock;
use App\Tests\Support\Secret\FixedTokenGenerator;
use App\Tests\Support\Secret\InMemorySecretRepository;
use App\Tests\Support\Secret\RecordingCryptography;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CreateSecretHandlerTest extends TestCase
{
    private const string BUSY_TOKEN = '00000000000000000000000000000001';
    private const string FREE_TOKEN = '00000000000000000000000000000002';

    public function testCreatesAndSavesSecretOnce(): void
    {
        $now = new DateTimeImmutable('2026-03-01 10:00:00');
        $payload = $this->payload();
        $repository = new InMemorySecretRepository();
        $handler = $this->handler($repository, new RecordingCryptography($payload), $now, [self::FREE_TOKEN]);

        $result = $handler(new CreateSecret('Секрет', 'подсказка', 'ключ'));

        self::assertSame(self::FREE_TOKEN, $result->token->value);
        self::assertSame(1, $repository->count());

        $secret = $repository->findByToken($result->token);
        self::assertInstanceOf(Secret::class, $secret);
        self::assertSame(self::FREE_TOKEN, $secret->token()->value);
        self::assertSame('подсказка', $secret->hint()?->value);
        self::assertSame($payload, $secret->payload());
        self::assertSame(
            $now->add(new DateInterval('P5D'))->format('Y-m-d H:i:s'),
            $secret->expiresAt()->format('Y-m-d H:i:s'),
        );

        $events = $secret->pullDomainEvents();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(SecretCreated::class, $event);
        self::assertSame(self::FREE_TOKEN, $event->token->value);
    }

    public function testUsesNextTokenWhenGeneratedTokenAlreadyExists(): void
    {
        $now = new DateTimeImmutable('2026-03-01 10:00:00');
        $repository = new InMemorySecretRepository();
        $repository->save($this->existingSecret(self::BUSY_TOKEN, $now));
        $handler = $this->handler($repository, new RecordingCryptography($this->payload()), $now, [self::BUSY_TOKEN, self::FREE_TOKEN]);

        $result = $handler(new CreateSecret('Секрет', null, 'ключ'));

        self::assertSame(self::FREE_TOKEN, $result->token->value);
        self::assertSame(2, $repository->count());
    }

    public function testPassesCommandPlaintextAndSecretToCryptography(): void
    {
        $cryptography = new RecordingCryptography($this->payload());
        $repository = new InMemorySecretRepository();
        $handler = $this->handler($repository, $cryptography, new DateTimeImmutable(), [self::FREE_TOKEN]);

        $handler(new CreateSecret('Открытый текст', null, 'секретный ключ'));

        self::assertCount(1, $cryptography->encryptions);
        self::assertSame('Открытый текст', $cryptography->encryptions[0]['plaintext']->value);
        self::assertSame('секретный ключ', $cryptography->encryptions[0]['secret']->value);
    }

    public function testBlankHintIsStoredAsNull(): void
    {
        $repository = new InMemorySecretRepository();
        $handler = $this->handler($repository, new RecordingCryptography($this->payload()), new DateTimeImmutable(), [self::FREE_TOKEN]);

        $result = $handler(new CreateSecret('Секрет', '   ', 'ключ'));

        $secret = $repository->findByToken($result->token);
        self::assertInstanceOf(Secret::class, $secret);
        self::assertNull($secret->hint());
    }

    /**
     * @param list<string> $tokens
     */
    private function handler(InMemorySecretRepository $repository, RecordingCryptography $cryptography, DateTimeImmutable $now, array $tokens): CreateSecretHandler
    {
        $tokenObjects = array_map(static fn (string $value): Token => new Token($value), $tokens);

        return new CreateSecretHandler(
            $cryptography,
            new UniqueTokenGenerator(new FixedTokenGenerator($tokenObjects), $repository),
            $repository,
            new FixedClock($now),
            Ttl::fromDays(5),
        );
    }

    private function existingSecret(string $token, DateTimeImmutable $createdAt): Secret
    {
        return Secret::create(new Token($token), null, $this->payload(), $createdAt, Ttl::fromDays(5));
    }

    private function payload(): EncryptedPayload
    {
        return new EncryptedPayload(str_repeat('s', 16), str_repeat('n', 24), 'ciphertext');
    }
}
