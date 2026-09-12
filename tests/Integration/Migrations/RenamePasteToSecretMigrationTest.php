<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;

final class RenamePasteToSecretMigrationTest extends TestCase
{
    private const string LEGACY_TABLE = 'paste';
    private const string TARGET_TABLE = 'secret';
    private const string LEGACY_VERSION = 'DoctrineMigrations\\Version20260909000000';
    private const string RENAME_VERSION = 'DoctrineMigrations\\Version20260910000000';
    private const string NEW_TOKEN = 'dddddddddddddddddddddddddddddddd';
    private const int LEGACY_MAX_ID = 9;

    public function testSqliteVersionSupportsAlterTableRename(): void
    {
        $this->assertSqliteSupportsRename($this->createConnection());
    }

    public function testMigrationRenamesPasteToSecretPreservingDataIndexesAndAutoincrement(): void
    {
        $this->assertMigrationRoundTrip($this->createConnection());
    }

    public function testMigrationRoundTripOnFileDatabasePreservesRowsAndSequence(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'copy-paste-migration-');
        self::assertIsString($path);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);

        try {
            $this->assertMigrationRoundTrip($connection);
        } finally {
            $connection->close();
            self::assertTrue(unlink($path));
        }
    }

    private function assertMigrationRoundTrip(Connection $connection): void
    {
        $this->assertSqliteSupportsRename($connection);

        $this->runMigration($connection, self::LEGACY_VERSION, Direction::UP);
        self::assertTrue($this->tableExists($connection, self::LEGACY_TABLE));
        $this->seedLegacyRows($connection);

        $before = $this->fetchComparableRows($connection, self::LEGACY_TABLE);
        self::assertCount(3, $before);
        self::assertSame(
            [1, 5, 9],
            array_map(static fn (array $row): int => (int) $row['id'], $before),
        );
        $this->assertIndexes($connection, self::LEGACY_TABLE, 'UNIQ_9C5678985F37A13B', 'idx_paste_expires_at');

        $this->runMigration($connection, self::RENAME_VERSION, Direction::UP);

        self::assertFalse($this->tableExists($connection, self::LEGACY_TABLE));
        self::assertTrue($this->tableExists($connection, self::TARGET_TABLE));
        self::assertSame($before, $this->fetchComparableRows($connection, self::TARGET_TABLE));
        $this->assertIndexes($connection, self::TARGET_TABLE, 'uniq_secret_token', 'idx_secret_expires_at');
        self::assertSame([self::TARGET_TABLE], $this->fetchSequenceNames($connection));

        $this->runMigration($connection, self::RENAME_VERSION, Direction::DOWN);

        self::assertFalse($this->tableExists($connection, self::TARGET_TABLE));
        self::assertTrue($this->tableExists($connection, self::LEGACY_TABLE));
        self::assertSame($before, $this->fetchComparableRows($connection, self::LEGACY_TABLE));
        $this->assertIndexes($connection, self::LEGACY_TABLE, 'UNIQ_9C5678985F37A13B', 'idx_paste_expires_at');
        self::assertSame([self::LEGACY_TABLE], $this->fetchSequenceNames($connection));

        $this->runMigration($connection, self::RENAME_VERSION, Direction::UP);

        self::assertFalse($this->tableExists($connection, self::LEGACY_TABLE));
        self::assertTrue($this->tableExists($connection, self::TARGET_TABLE));
        self::assertSame($before, $this->fetchComparableRows($connection, self::TARGET_TABLE));
        $this->assertIndexes($connection, self::TARGET_TABLE, 'uniq_secret_token', 'idx_secret_expires_at');

        $this->insertRowWithoutId($connection);

        $maxId = $connection->fetchOne('SELECT MAX(id) FROM '.self::TARGET_TABLE);
        self::assertIsNumeric($maxId);
        self::assertGreaterThan(self::LEGACY_MAX_ID, (int) $maxId);
        self::assertSame([self::TARGET_TABLE], $this->fetchSequenceNames($connection));
    }

    private function createConnection(): Connection
    {
        $params = (new DsnParser(['sqlite' => 'pdo_sqlite']))->parse('sqlite:///:memory:');

        return DriverManager::getConnection($params);
    }

    private function createDependencyFactory(Connection $connection): DependencyFactory
    {
        $configuration = new Configuration();
        $configuration->addMigrationsDirectory('DoctrineMigrations', dirname(__DIR__, 3).'/migrations');
        $configuration->setAllOrNothing(false);
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());

        $factory = DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($connection),
        );
        $factory->getMetadataStorage()->ensureInitialized();

        return $factory;
    }

    private function runMigration(Connection $connection, string $version, string $direction): void
    {
        $factory = $this->createDependencyFactory($connection);
        $factory->getMigrator()->migrate(
            $factory->getMigrationPlanCalculator()->getPlanForVersions([new Version($version)], $direction),
            new MigratorConfiguration(),
        );
    }

    private function seedLegacyRows(Connection $connection): void
    {
        $salt = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f";
        $nonce = "\x00\xff\x01\xfe\x02\xfd\x03\xfc\x04\xfb\x05\xfa\x06\xf9\x07\xf8\x08\xf7\x09\xf6\x0a\xf5\x0b\x0c";
        $ciphertext = "\x00\x00secret\x00\x00";

        /** @var list<array{int, string, ?string, string, string}> $rows */
        $rows = [
            [1, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'подсказка', '2026-04-01 09:00:00', '2026-04-08 09:00:00'],
            [5, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', null, '2026-04-02 09:00:00', '2026-04-09 09:00:00'],
            [9, 'cccccccccccccccccccccccccccccccc', '', '2026-04-03 09:00:00', '2026-04-10 09:00:00'],
        ];

        foreach ($rows as [$id, $token, $hint, $createdAt, $expiresAt]) {
            $connection->insert(self::LEGACY_TABLE, [
                'id' => $id,
                'token' => $token,
                'hint' => $hint,
                'salt' => $salt,
                'nonce' => $nonce,
                'ciphertext' => $ciphertext,
                'created_at' => new DateTimeImmutable($createdAt),
                'expires_at' => new DateTimeImmutable($expiresAt),
            ], [
                'id' => Types::INTEGER,
                'salt' => Types::BLOB,
                'nonce' => Types::BLOB,
                'ciphertext' => Types::BLOB,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        }
    }

    private function insertRowWithoutId(Connection $connection): void
    {
        $connection->insert(self::TARGET_TABLE, [
            'token' => self::NEW_TOKEN,
            'hint' => null,
            'salt' => "\x00\x0a",
            'nonce' => "\x00\x0b",
            'ciphertext' => "\x00\x0c",
            'created_at' => new DateTimeImmutable('2026-05-01 00:00:00'),
            'expires_at' => new DateTimeImmutable('2026-05-08 00:00:00'),
        ], [
            'salt' => Types::BLOB,
            'nonce' => Types::BLOB,
            'ciphertext' => Types::BLOB,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * @return list<array<string, string>>
     */
    private function fetchComparableRows(Connection $connection, string $table): array
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT CAST(id AS TEXT) AS id, token, CASE WHEN hint IS NULL THEN \'<null>\' ELSE hint END AS hint, '
            .'hex(salt) AS salt, hex(nonce) AS nonce, hex(ciphertext) AS ciphertext, '
            .'created_at, expires_at FROM '.$table.' ORDER BY CAST(id AS INTEGER)',
        );

        $result = [];
        foreach ($rows as $row) {
            $normalized = [];
            foreach ($row as $column => $value) {
                self::assertIsString($value);
                $normalized[$column] = $value;
            }
            $result[] = $normalized;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function fetchSequenceNames(Connection $connection): array
    {
        $rows = $connection->fetchAllAssociative('SELECT name FROM sqlite_sequence ORDER BY name');

        $names = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    private function tableExists(Connection $connection, string $table): bool
    {
        $count = $connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name",
            ['name' => $table],
        );
        self::assertIsNumeric($count);

        return 1 === (int) $count;
    }

    private function assertIndexes(Connection $connection, string $table, string $uniqueIndex, string $plainIndex): void
    {
        $names = $this->fetchIndexNames($connection, $table);
        sort($names);

        $expected = [$uniqueIndex, $plainIndex];
        sort($expected);

        self::assertSame($expected, $names);
        self::assertSame(['token'], $this->fetchIndexColumns($connection, $uniqueIndex));
        self::assertSame(['expires_at'], $this->fetchIndexColumns($connection, $plainIndex));
    }

    /**
     * @return list<string>
     */
    private function fetchIndexNames(Connection $connection, string $table): array
    {
        $rows = $connection->fetchAllAssociative('PRAGMA index_list('.$table.')');

        $names = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function fetchIndexColumns(Connection $connection, string $index): array
    {
        $rows = $connection->fetchAllAssociative('PRAGMA index_info('.$index.')');

        $columns = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            self::assertIsString($name);
            $columns[] = $name;
        }

        return $columns;
    }

    private function assertSqliteSupportsRename(Connection $connection): void
    {
        $version = $connection->fetchOne('SELECT sqlite_version()');
        self::assertIsString($version);
        self::assertTrue(
            version_compare($version, '3.25.0', '>='),
            sprintf('SQLite %s does not support ALTER TABLE ... RENAME semantics (>= 3.25.0 required).', $version),
        );
    }
}
