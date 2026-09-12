<?php

declare(strict_types=1);

namespace App\Tests\Integration\Secret\Infrastructure\Persistence\Doctrine;

use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Model\Ttl;
use App\Secret\Domain\Repository\SecretRepository;
use App\Secret\Infrastructure\Persistence\Doctrine\DoctrineSecretRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineSecretRepositoryPurgeTest extends KernelTestCase
{
    private const string PAST_TOKEN = '11111111111111111111111111111111';
    private const string BOUNDARY_TOKEN = '22222222222222222222222222222222';
    private const string FUTURE_TOKEN = '33333333333333333333333333333333';

    public function testDeletesSecretsExpiringBeforeOrExactlyAtCutoffAndKeepsActive(): void
    {
        self::bootKernel();
        $this->recreateSchema();
        $repository = $this->repository();
        $now = new DateTimeImmutable('2026-03-01 10:00:00');

        $repository->save($this->secret(self::PAST_TOKEN, $now->sub(new DateInterval('PT1S'))));
        $repository->save($this->secret(self::BOUNDARY_TOKEN, $now));
        $repository->save($this->secret(self::FUTURE_TOKEN, $now->add(new DateInterval('PT1S'))));

        $count = $repository->removeExpiredBefore($now);

        self::assertSame(2, $count);
        self::assertNull($repository->findByToken(new Token(self::PAST_TOKEN)));
        self::assertNull($repository->findByToken(new Token(self::BOUNDARY_TOKEN)));
        self::assertNotNull($repository->findByToken(new Token(self::FUTURE_TOKEN)));
    }

    public function testReturnsZeroAndKeepsRowsWhenNothingExpired(): void
    {
        self::bootKernel();
        $this->recreateSchema();
        $repository = $this->repository();
        $now = new DateTimeImmutable('2026-03-01 10:00:00');

        $repository->save($this->secret(self::FUTURE_TOKEN, $now->add(new DateInterval('PT1S'))));

        $count = $repository->removeExpiredBefore($now);

        self::assertSame(0, $count);
        self::assertNotNull($repository->findByToken(new Token(self::FUTURE_TOKEN)));
    }

    public function testSecondRunReturnsZeroAndKeepsActiveSecret(): void
    {
        self::bootKernel();
        $this->recreateSchema();
        $repository = $this->repository();
        $now = new DateTimeImmutable('2026-03-01 10:00:00');

        $repository->save($this->secret(self::PAST_TOKEN, $now->sub(new DateInterval('PT1S'))));
        $repository->save($this->secret(self::FUTURE_TOKEN, $now->add(new DateInterval('PT1S'))));

        self::assertSame(1, $repository->removeExpiredBefore($now));
        self::assertSame(0, $repository->removeExpiredBefore($now));
        self::assertNotNull($repository->findByToken(new Token(self::FUTURE_TOKEN)));
    }

    public function testEnablesWalAndBusyTimeoutOnConnection(): void
    {
        self::bootKernel();
        $this->recreateSchema();
        $repository = $this->repository();
        $connection = $this->connection();

        $repository->removeExpiredBefore(new DateTimeImmutable('2026-03-01 10:00:00'));

        self::assertSame('wal', $connection->fetchOne('PRAGMA journal_mode'));
        self::assertSame(5000, $connection->fetchOne('PRAGMA busy_timeout'));
    }

    private function recreateSchema(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    private function repository(): DoctrineSecretRepository
    {
        $repository = self::getContainer()->get(SecretRepository::class);
        self::assertInstanceOf(DoctrineSecretRepository::class, $repository);

        return $repository;
    }

    private function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
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
