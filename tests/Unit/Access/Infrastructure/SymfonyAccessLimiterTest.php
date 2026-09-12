<?php

declare(strict_types=1);

namespace App\Tests\Unit\Access\Infrastructure;

use App\Access\Domain\AccessDecision;
use App\Access\Domain\AccessLimit;
use App\Access\Infrastructure\Symfony\SymfonyAccessLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class SymfonyAccessLimiterTest extends TestCase
{
    private const string CLIENT_KEY = '203.0.113.7';

    public function testCreateSecretIsAcceptedTenTimesThenRejected(): void
    {
        $limiter = $this->limiter();

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            self::assertSame(AccessDecision::Accepted, $limiter->consume(AccessLimit::CreateSecret, self::CLIENT_KEY));
        }

        self::assertSame(AccessDecision::Rejected, $limiter->consume(AccessLimit::CreateSecret, self::CLIENT_KEY));
    }

    public function testRevealSecretIsAcceptedTenTimesThenRejectedForTheSameKey(): void
    {
        $limiter = $this->limiter();
        $key = self::CLIENT_KEY.':'.str_repeat('a', 32);

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            self::assertSame(AccessDecision::Accepted, $limiter->consume(AccessLimit::RevealSecret, $key));
        }

        self::assertSame(AccessDecision::Rejected, $limiter->consume(AccessLimit::RevealSecret, $key));
    }

    public function testDifferentKeysHaveIndependentWindows(): void
    {
        $limiter = $this->limiter(createLimit: 2);

        self::assertSame(AccessDecision::Accepted, $limiter->consume(AccessLimit::CreateSecret, 'key-a'));
        self::assertSame(AccessDecision::Accepted, $limiter->consume(AccessLimit::CreateSecret, 'key-a'));
        self::assertSame(AccessDecision::Rejected, $limiter->consume(AccessLimit::CreateSecret, 'key-a'));

        self::assertSame(AccessDecision::Accepted, $limiter->consume(AccessLimit::CreateSecret, 'key-b'));
    }

    public function testDifferentLimitsHaveIndependentWindowsForTheSameKey(): void
    {
        $limiter = $this->limiter(createLimit: 1, revealLimit: 1);

        self::assertSame(AccessDecision::Accepted, $limiter->consume(AccessLimit::CreateSecret, self::CLIENT_KEY));
        self::assertSame(AccessDecision::Accepted, $limiter->consume(AccessLimit::RevealSecret, self::CLIENT_KEY));
        self::assertSame(AccessDecision::Rejected, $limiter->consume(AccessLimit::CreateSecret, self::CLIENT_KEY));
        self::assertSame(AccessDecision::Rejected, $limiter->consume(AccessLimit::RevealSecret, self::CLIENT_KEY));
    }

    public function testEachLimitIsConsumedThroughItsOwnConfiguredFactory(): void
    {
        $limiter = $this->limiter(createLimit: 2, revealLimit: 3);

        self::assertSame(AccessDecision::Accepted, $limiter->consume(AccessLimit::CreateSecret, self::CLIENT_KEY));
        self::assertSame(AccessDecision::Accepted, $limiter->consume(AccessLimit::CreateSecret, self::CLIENT_KEY));
        self::assertSame(AccessDecision::Rejected, $limiter->consume(AccessLimit::CreateSecret, self::CLIENT_KEY));

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            self::assertSame(AccessDecision::Accepted, $limiter->consume(AccessLimit::RevealSecret, self::CLIENT_KEY));
        }

        self::assertSame(AccessDecision::Rejected, $limiter->consume(AccessLimit::RevealSecret, self::CLIENT_KEY));
    }

    private function limiter(int $createLimit = 10, int $revealLimit = 10): SymfonyAccessLimiter
    {
        $storage = new InMemoryStorage();

        return new SymfonyAccessLimiter(
            $this->factory('create_paste', $createLimit, $storage),
            $this->factory('unlock_paste', $revealLimit, $storage),
        );
    }

    private function factory(string $id, int $limit, InMemoryStorage $storage): RateLimiterFactory
    {
        return new RateLimiterFactory([
            'id' => $id,
            'policy' => 'fixed_window',
            'limit' => $limit,
            'interval' => '1 minute',
        ], $storage);
    }
}
