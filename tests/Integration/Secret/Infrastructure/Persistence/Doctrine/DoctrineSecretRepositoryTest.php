<?php

declare(strict_types=1);

namespace App\Tests\Integration\Secret\Infrastructure\Persistence\Doctrine;

use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Hint;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Model\Ttl;
use App\Secret\Domain\Repository\SecretRepository;
use App\Secret\Infrastructure\Persistence\Doctrine\DoctrineSecretRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineSecretRepositoryTest extends KernelTestCase
{
    private const string TOKEN = '0123456789abcdef0123456789abcdef';
    private const string RAW_TOKEN = 'fedcba9876543210fedcba9876543210';

    /** @phpstan-ignore property.uninitialized (initialized in setUp(); review 2027-06-30) */
    private DoctrineSecretRepository $repository;
    /** @phpstan-ignore property.uninitialized (initialized in setUp(); review 2027-06-30) */
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $repository = $container->get(SecretRepository::class);
        self::assertInstanceOf(DoctrineSecretRepository::class, $repository);
        $this->repository = $repository;

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();

        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testSavePersistsRowWithExpectedColumns(): void
    {
        $secret = $this->newSecret();

        $this->repository->save($secret);

        $row = $this->connection->fetchAssociative(
            'SELECT token, hint, salt, nonce, ciphertext, created_at, expires_at FROM paste WHERE token = :token',
            ['token' => self::TOKEN],
        );

        self::assertIsArray($row);
        self::assertSame(self::TOKEN, $row['token']);
        self::assertSame('подсказка', $row['hint']);
        self::assertSame($secret->payload()->salt, $this->blob($row['salt']));
        self::assertSame($secret->payload()->nonce, $this->blob($row['nonce']));
        self::assertSame($secret->payload()->ciphertext, $this->blob($row['ciphertext']));
        self::assertSame($secret->createdAt()->format('Y-m-d H:i:s'), $this->date($row['created_at'])->format('Y-m-d H:i:s'));
        self::assertSame($secret->expiresAt()->format('Y-m-d H:i:s'), $this->date($row['expires_at'])->format('Y-m-d H:i:s'));
    }

    public function testFindByTokenReturnsAggregateWithByteIdenticalPayload(): void
    {
        $secret = $this->newSecret();
        $this->repository->save($secret);

        $found = $this->repository->findByToken(new Token(self::TOKEN));

        self::assertInstanceOf(Secret::class, $found);
        self::assertSame(self::TOKEN, $found->token()->value);
        self::assertSame('подсказка', $found->hint()?->value);
        self::assertSame($secret->payload()->salt, $found->payload()->salt);
        self::assertSame($secret->payload()->nonce, $found->payload()->nonce);
        self::assertSame($secret->payload()->ciphertext, $found->payload()->ciphertext);
        self::assertSame($secret->createdAt()->format('Y-m-d H:i:s'), $found->createdAt()->format('Y-m-d H:i:s'));
        self::assertSame($secret->expiresAt()->format('Y-m-d H:i:s'), $found->expiresAt()->format('Y-m-d H:i:s'));
        self::assertSame([], $found->pullDomainEvents());
    }

    public function testFindByTokenReturnsNullForUnknownToken(): void
    {
        self::assertNull($this->repository->findByToken(new Token(self::TOKEN)));
    }

    public function testTokenExistsDistinguishesStoredAndUnknownTokens(): void
    {
        self::assertFalse($this->repository->tokenExists(new Token(self::TOKEN)));

        $this->repository->save($this->newSecret());

        self::assertTrue($this->repository->tokenExists(new Token(self::TOKEN)));
        self::assertFalse($this->repository->tokenExists(new Token(self::RAW_TOKEN)));
    }

    public function testFindByTokenReadsBinaryColumnsWithNullBytes(): void
    {
        $salt = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f";
        $nonce = "\x00\xff\x01\xfe\x02\xfd\x03\xfc\x04\xfb\x05\xfa\x06\xf9\x07\xf8\x08\xf7\x09\xf6\x0a\xf5\x0b\x0c";
        $ciphertext = "\x00\x00secret\x00\x00";
        $createdAt = new DateTimeImmutable('2026-04-01 09:00:00');
        $expiresAt = new DateTimeImmutable('2026-04-08 09:00:00');

        $this->connection->insert('paste', [
            'token' => self::RAW_TOKEN,
            'hint' => null,
            'salt' => $salt,
            'nonce' => $nonce,
            'ciphertext' => $ciphertext,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ], [
            'salt' => Types::BLOB,
            'nonce' => Types::BLOB,
            'ciphertext' => Types::BLOB,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);

        $found = $this->repository->findByToken(new Token(self::RAW_TOKEN));

        self::assertInstanceOf(Secret::class, $found);
        self::assertNull($found->hint());
        self::assertSame($salt, $found->payload()->salt);
        self::assertSame($nonce, $found->payload()->nonce);
        self::assertSame($ciphertext, $found->payload()->ciphertext);
    }

    private function newSecret(): Secret
    {
        $hint = Hint::fromUserInput('подсказка');
        self::assertInstanceOf(Hint::class, $hint);

        return Secret::create(
            new Token(self::TOKEN),
            $hint,
            new EncryptedPayload(random_bytes(16), random_bytes(24), random_bytes(40)),
            new DateTimeImmutable('2026-04-01 09:00:00'),
            Ttl::fromDays(7),
        );
    }

    private function blob(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            self::assertIsString($contents);

            return $contents;
        }

        self::assertIsString($value);

        return $value;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        self::assertIsString($value);

        return new DateTimeImmutable($value);
    }
}
