<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Infrastructure\Persistence\Doctrine;

use App\Secret\Infrastructure\Persistence\Doctrine\DoctrineSecretRepository;
use App\Secret\Infrastructure\Persistence\Doctrine\SecretRecordMapper;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;

final class DoctrineSecretRepositoryMaintenanceTest extends TestCase
{
    public function testRunsSqliteMaintenanceInLegacyOrderAndBindsInclusiveCutoff(): void
    {
        $now = new DateTimeImmutable('2026-03-01 10:00:00');
        /** @var list<string> $statements */
        $statements = [];
        /** @var array<string, mixed> $deleteParams */
        $deleteParams = [];
        /** @var array<string, mixed> $deleteTypes */
        $deleteTypes = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            /**
             * @param array<int|string, mixed> $params
             * @param array<int|string, mixed> $types
             */
            function (string $sql, array $params = [], array $types = []) use (&$statements, &$deleteParams, &$deleteTypes): int {
                $statements[] = $sql;
                if (str_starts_with($sql, 'DELETE')) {
                    $deleteParams = $params;
                    $deleteTypes = $types;

                    return 2;
                }

                return 0;
            },
        );

        $repository = new DoctrineSecretRepository($connection, new SecretRecordMapper());

        $count = $repository->removeExpiredBefore($now);

        self::assertSame(2, $count);
        self::assertSame([
            'PRAGMA journal_mode=WAL',
            'PRAGMA busy_timeout=5000',
            'DELETE FROM secret WHERE expires_at <= :now',
            'PRAGMA incremental_vacuum',
        ], $statements);
        self::assertSame(['now' => $now], $deleteParams);
        self::assertSame(['now' => Types::DATETIME_IMMUTABLE], $deleteTypes);
    }

    public function testSkipsIncrementalVacuumWhenDeleteAffectsNoRows(): void
    {
        /** @var list<string> $statements */
        $statements = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            },
        );

        $repository = new DoctrineSecretRepository($connection, new SecretRecordMapper());

        $count = $repository->removeExpiredBefore(new DateTimeImmutable('2026-03-01 10:00:00'));

        self::assertSame(0, $count);
        self::assertSame([
            'PRAGMA journal_mode=WAL',
            'PRAGMA busy_timeout=5000',
            'DELETE FROM secret WHERE expires_at <= :now',
        ], $statements);
    }
}
