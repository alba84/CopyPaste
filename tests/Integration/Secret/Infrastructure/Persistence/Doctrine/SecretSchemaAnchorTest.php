<?php

declare(strict_types=1);

namespace App\Tests\Integration\Secret\Infrastructure\Persistence\Doctrine;

use App\Secret\Infrastructure\Persistence\Doctrine\Schema\SecretSchemaAnchor;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SecretSchemaAnchorTest extends KernelTestCase
{
    private const array COLUMNS = ['id', 'token', 'hint', 'salt', 'nonce', 'ciphertext', 'created_at', 'expires_at'];

    public function testOrmMetadataDescribesSecretTableColumnsAndExplicitIndexNames(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();

        $metadata = $entityManager->getClassMetadata(SecretSchemaAnchor::class);

        self::assertSame('secret', $metadata->getTableName());

        $schemaTool = new SchemaTool($entityManager);
        $table = $schemaTool->getSchemaFromMetadata([$metadata])->getTable('secret');

        foreach (self::COLUMNS as $column) {
            self::assertTrue($table->hasColumn($column), sprintf('Missing column "%s".', $column));
        }
        self::assertCount(count(self::COLUMNS), $table->getColumns());

        self::assertTrue($table->hasIndex('idx_secret_expires_at'));
        $indexedColumns = array_map(
            static fn (IndexedColumn $indexedColumn): string => $indexedColumn->getColumnName()->toString(),
            $table->getIndex('idx_secret_expires_at')->getIndexedColumns(),
        );
        self::assertSame(['expires_at'], $indexedColumns);

        self::assertTrue($table->hasIndex('uniq_secret_token'));
        self::assertSame(IndexType::UNIQUE, $table->getIndex('uniq_secret_token')->getType());
        self::assertSame(
            ['token'],
            array_map(
                static fn (IndexedColumn $indexedColumn): string => $indexedColumn->getColumnName()->toString(),
                $table->getIndex('uniq_secret_token')->getIndexedColumns(),
            ),
        );

        $uniqueTokenIndexes = array_filter(
            $table->getIndexes(),
            static fn (Index $index): bool => IndexType::UNIQUE === $index->getType() && ['token'] === array_map(
                static fn (IndexedColumn $indexedColumn): string => $indexedColumn->getColumnName()->toString(),
                $index->getIndexedColumns(),
            ),
        );
        self::assertCount(1, $uniqueTokenIndexes, 'The "token" column must have exactly one unique index.');
    }

    public function testSchemaIsInSyncWithMetadata(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $this->recreateSchema($entityManager);

        $configuration = $entityManager->getConnection()->getConfiguration();
        $previousFilter = $configuration->getSchemaAssetsFilter();
        $configuration->setSchemaAssetsFilter(static fn (string $asset): bool => 'secret' === $asset);

        try {
            $validator = new SchemaValidator($entityManager);
            self::assertTrue($validator->schemaInSyncWithMetadata());

            $schemaTool = new SchemaTool($entityManager);
            self::assertSame(
                [],
                $schemaTool->getUpdateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata()),
            );
        } finally {
            $configuration->setSchemaAssetsFilter($previousFilter);
        }
    }

    public function testPhysicalTableColumnsMatchOrmMetadata(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $this->recreateSchema($entityManager);

        $rows = $entityManager->getConnection()->fetchAllAssociative('PRAGMA table_info(secret)');
        $physical = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            self::assertIsString($name);
            $physical[] = $name;
        }

        $metadata = $entityManager->getClassMetadata(SecretSchemaAnchor::class);
        $expected = array_values($metadata->getColumnNames());

        sort($physical);
        sort($expected);

        self::assertSame($expected, $physical);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function recreateSchema(EntityManagerInterface $entityManager): void
    {
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }
}
