<?php

declare(strict_types=1);

namespace App\Secret\Infrastructure\Persistence\Doctrine;

use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Repository\SecretRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class DoctrineSecretRepository implements SecretRepository
{
    public function __construct(
        private Connection $connection,
        private SecretRecordMapper $mapper,
    ) {
    }

    public function save(Secret $secret): void
    {
        $record = $this->mapper->toRecord($secret);

        $this->connection->insert('paste', [
            'token' => $record->token,
            'hint' => $record->hint,
            'salt' => $record->salt,
            'nonce' => $record->nonce,
            'ciphertext' => $record->ciphertext,
            'created_at' => $record->createdAt,
            'expires_at' => $record->expiresAt,
        ], [
            'salt' => Types::BLOB,
            'nonce' => Types::BLOB,
            'ciphertext' => Types::BLOB,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    public function findByToken(Token $token): ?Secret
    {
        $row = $this->connection->fetchAssociative(
            'SELECT token, hint, salt, nonce, ciphertext, created_at, expires_at FROM paste WHERE token = :token',
            ['token' => $token->value],
        );

        if (false === $row) {
            return null;
        }

        return $this->mapper->fromRow($row);
    }

    public function tokenExists(Token $token): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM paste WHERE token = :token',
            ['token' => $token->value],
        );
    }
}
