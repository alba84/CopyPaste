<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Domain\Model;

use App\Secret\Domain\Event\SecretCreated;
use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Hint;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Model\Ttl;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SecretTest extends TestCase
{
    private const string TOKEN = '0123456789abcdef0123456789abcdef';

    public function testCreateComputesExpirationFromTtl(): void
    {
        $createdAt = new DateTimeImmutable('2026-02-01 12:00:00');
        $ttl = Ttl::fromDays(3);
        $secret = Secret::create($this->token(), $this->hint(), $this->payload(), $createdAt, $ttl);

        self::assertSame(self::TOKEN, $secret->token()->value);
        self::assertNotNull($secret->hint());
        self::assertSame('подсказка', $secret->hint()->value);
        self::assertSame('ciphertext', $secret->payload()->ciphertext);
        self::assertSame($createdAt, $secret->createdAt());
        self::assertSame(
            $createdAt->add(new DateInterval('P3D'))->format('Y-m-d H:i:s'),
            $secret->expiresAt()->format('Y-m-d H:i:s'),
        );
    }

    public function testCreateRecordsExactlyOneSecretCreatedEvent(): void
    {
        $createdAt = new DateTimeImmutable('2026-02-01 12:00:00');
        $secret = Secret::create($this->token(), null, $this->payload(), $createdAt, Ttl::fromDays(1));

        $events = $secret->pullDomainEvents();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(SecretCreated::class, $event);
        self::assertSame(self::TOKEN, $event->token->value);
        self::assertSame($createdAt, $event->createdAt);
    }

    public function testPullDomainEventsReturnsEventsOnlyOnce(): void
    {
        $secret = Secret::create($this->token(), null, $this->payload(), new DateTimeImmutable(), Ttl::fromDays(1));

        self::assertCount(1, $secret->pullDomainEvents());
        self::assertSame([], $secret->pullDomainEvents());
    }

    public function testIsExpiredAtTreatsExpirationMomentAsExpired(): void
    {
        $createdAt = new DateTimeImmutable('2026-02-01 12:00:00');
        $secret = Secret::create($this->token(), null, $this->payload(), $createdAt, Ttl::fromDays(1));
        $expiresAt = $secret->expiresAt();

        self::assertFalse($secret->isExpiredAt($expiresAt->sub(new DateInterval('PT1S'))));
        self::assertTrue($secret->isExpiredAt($expiresAt));
        self::assertTrue($secret->isExpiredAt($expiresAt->add(new DateInterval('PT1S'))));
    }

    public function testReconstituteDoesNotRecordDomainEvents(): void
    {
        $createdAt = new DateTimeImmutable('2026-02-01 12:00:00');
        $secret = Secret::reconstitute($this->token(), null, $this->payload(), $createdAt, $createdAt->add(new DateInterval('P1D')));

        self::assertSame([], $secret->pullDomainEvents());
    }

    private function token(): Token
    {
        return new Token(self::TOKEN);
    }

    private function hint(): Hint
    {
        $hint = Hint::fromUserInput('подсказка');
        self::assertNotNull($hint);

        return $hint;
    }

    private function payload(): EncryptedPayload
    {
        return new EncryptedPayload(str_repeat('s', 16), str_repeat('n', 24), 'ciphertext');
    }
}
