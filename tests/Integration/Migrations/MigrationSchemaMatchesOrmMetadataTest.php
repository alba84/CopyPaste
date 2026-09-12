<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Secret\Infrastructure\Persistence\Doctrine\Schema\SecretSchemaAnchor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Proves that a database created exclusively by migrations (the CI/prod path)
 * is in sync with the ORM metadata. The mirror test in SecretSchemaAnchorTest
 * starts from the ORM metadata instead, so it cannot catch migration/anchor drift.
 */
final class MigrationSchemaMatchesOrmMetadataTest extends TestCase
{
    private const string LEGACY_VERSION = 'DoctrineMigrations\\Version20260909000000';
    private const string RENAME_VERSION = 'DoctrineMigrations\\Version20260910000000';

    public function testDatabaseBuiltByMigrationsMatchesOrmMetadata(): void
    {
        $connection = $this->createConnection();
        $this->runMigration($connection, self::LEGACY_VERSION, Direction::UP);
        $this->runMigration($connection, self::RENAME_VERSION, Direction::UP);

        self::assertSame(1, $this->tableCount($connection, 'secret'));
        self::assertSame(0, $this->tableCount($connection, 'paste'));

        $entityManager = $this->createEntityManager($connection);
        $metadata = $entityManager->getClassMetadata(SecretSchemaAnchor::class);
        self::assertSame('secret', $metadata->getTableName());

        $configuration = $connection->getConfiguration();
        $previousFilter = $configuration->getSchemaAssetsFilter();
        $configuration->setSchemaAssetsFilter(static fn (string $asset): bool => 'secret' === $asset);

        try {
            $validator = new SchemaValidator($entityManager);
            self::assertTrue(
                $validator->schemaInSyncWithMetadata(),
                'The database created by migrations is not in sync with the ORM metadata.',
            );

            $schemaTool = new SchemaTool($entityManager);
            self::assertSame(
                [],
                $schemaTool->getUpdateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata()),
                'Doctrine computes schema updates for a migration-created database; migration and anchor drifted.',
            );
        } finally {
            $configuration->setSchemaAssetsFilter($previousFilter);
        }
    }

    public function testMigrationCreatedSecretHasTargetIndexNames(): void
    {
        $connection = $this->createConnection();
        $this->runMigration($connection, self::LEGACY_VERSION, Direction::UP);
        $this->runMigration($connection, self::RENAME_VERSION, Direction::UP);

        $indexNames = $this->fetchIndexNames($connection, 'secret');
        sort($indexNames);

        self::assertSame(['idx_secret_expires_at', 'uniq_secret_token'], $indexNames);
    }

    private function createConnection(): Connection
    {
        $params = (new DsnParser(['sqlite' => 'pdo_sqlite']))->parse('sqlite:///:memory:');

        return DriverManager::getConnection($params);
    }

    private function createEntityManager(Connection $connection): EntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfig(
            [dirname(__DIR__, 3).'/src/Secret/Infrastructure/Persistence/Doctrine/Schema'],
            true,
        );
        $configuration->setProxyDir(sys_get_temp_dir().'/copy-paste-orm-proxies');
        $configuration->setProxyNamespace('CopyPasteOrmProxies');
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy());

        return new EntityManager($connection, $configuration);
    }

    private function runMigration(Connection $connection, string $version, string $direction): void
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
        $factory->getMigrator()->migrate(
            $factory->getMigrationPlanCalculator()->getPlanForVersions([new Version($version)], $direction),
            new MigratorConfiguration(),
        );
    }

    private function tableCount(Connection $connection, string $table): int
    {
        $count = $connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name",
            ['name' => $table],
        );
        self::assertIsNumeric($count);

        return (int) $count;
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
}
