<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Domain\Model;

use App\Secret\Domain\Exception\InvalidTtl;
use App\Secret\Domain\Model\Ttl;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TtlTest extends TestCase
{
    public function testExpiresAtIsCreatedAtPlusConfiguredDays(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-10 08:30:00');
        $ttl = Ttl::fromDays(7);

        self::assertSame(7, $ttl->days);
        self::assertSame(
            $createdAt->add(new DateInterval('P7D'))->format('Y-m-d H:i:s'),
            $ttl->expiresAtFrom($createdAt)->format('Y-m-d H:i:s'),
        );
    }

    /** @return iterable<string, array{int}> */
    public static function nonPositiveDays(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('nonPositiveDays')]
    public function testRejectsNonPositiveDays(int $days): void
    {
        $this->expectException(InvalidTtl::class);

        Ttl::fromDays($days);
    }
}
