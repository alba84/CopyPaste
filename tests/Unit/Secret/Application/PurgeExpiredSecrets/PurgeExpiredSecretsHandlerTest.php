<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Application\PurgeExpiredSecrets;

use App\Secret\Application\PurgeExpiredSecrets\PurgeExpiredSecrets;
use App\Secret\Application\PurgeExpiredSecrets\PurgeExpiredSecretsHandler;
use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Model\Ttl;
use App\Tests\Support\Secret\FixedClock;
use App\Tests\Support\Secret\InMemorySecretRepository;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PurgeExpiredSecretsHandlerTest extends TestCase
{
    private const string PAST_TOKEN = '11111111111111111111111111111111';
    private const string BOUNDARY_TOKEN = '22222222222222222222222222222222';
    private const string FUTURE_TOKEN = '33333333333333333333333333333333';

    public function testRemovesSecretsExpiringAtOrBeforeClockNowAndReturnsCount(): void
    {
        $now = new DateTimeImmutable('2026-03-01 10:00:00');
        $repository = new InMemorySecretRepository();
        $past = $this->secret(self::PAST_TOKEN, $now->sub(new DateInterval('PT1S')));
        $boundary = $this->secret(self::BOUNDARY_TOKEN, $now);
        $future = $this->secret(self::FUTURE_TOKEN, $now->add(new DateInterval('PT1S')));
        $repository->save($past);
        $repository->save($boundary);
        $repository->save($future);

        $handler = new PurgeExpiredSecretsHandler($repository, new FixedClock($now));

        $result = $handler(new PurgeExpiredSecrets());

        self::assertSame(2, $result->deletedCount);
        self::assertNull($repository->findByToken($past->token()));
        self::assertNull($repository->findByToken($boundary->token()));
        self::assertNotNull($repository->findByToken($future->token()));
        self::assertSame($now, $repository->lastRemovedBefore());
    }

    public function testReturnsZeroWhenNothingExpired(): void
    {
        $now = new DateTimeImmutable('2026-03-01 10:00:00');
        $repository = new InMemorySecretRepository();
        $future = $this->secret(self::FUTURE_TOKEN, $now->add(new DateInterval('PT1S')));
        $repository->save($future);

        $handler = new PurgeExpiredSecretsHandler($repository, new FixedClock($now));

        $result = $handler(new PurgeExpiredSecrets());

        self::assertSame(0, $result->deletedCount);
        self::assertSame(1, $repository->count());
        self::assertNotNull($repository->findByToken($future->token()));
    }

    public function testDelegatesToRepositoryExactlyOnce(): void
    {
        $now = new DateTimeImmutable('2026-03-01 10:00:00');
        $repository = new InMemorySecretRepository();
        $repository->save($this->secret(self::PAST_TOKEN, $now->sub(new DateInterval('PT1S'))));
        $handler = new PurgeExpiredSecretsHandler($repository, new FixedClock($now));

        $first = $handler(new PurgeExpiredSecrets());
        $second = $handler(new PurgeExpiredSecrets());

        self::assertSame(1, $first->deletedCount);
        self::assertSame(0, $second->deletedCount);
        self::assertSame(0, $repository->count());
    }

    private function secret(string $token, DateTimeImmutable $expiresAt): Secret
    {
        return Secret::create(
            new Token($token),
            null,
            new EncryptedPayload(str_repeat('s', 16), str_repeat('n', 24), 'ciphertext'),
            $expiresAt->sub(new DateInterval('P1D')),
            Ttl::fromDays(1),
        );
    }
}
