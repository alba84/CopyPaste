<?php

declare(strict_types=1);

namespace App\Secret\Infrastructure\Persistence\Doctrine;

use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Hint;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\Token;
use DateTimeImmutable;
use RuntimeException;

final class SecretRecordMapper
{
    public function toRecord(Secret $secret): SecretRecord
    {
        $payload = $secret->payload();

        return new SecretRecord(
            $secret->token()->value,
            $secret->hint()?->value,
            $payload->salt,
            $payload->nonce,
            $payload->ciphertext,
            $secret->createdAt(),
            $secret->expiresAt(),
        );
    }

    /** @param array<string, mixed> $row */
    public function fromRow(array $row): Secret
    {
        return Secret::reconstitute(
            new Token($this->stringValue($row, 'token')),
            Hint::fromUserInput($this->nullableStringValue($row, 'hint')),
            new EncryptedPayload(
                $this->binaryValue($row, 'salt'),
                $this->binaryValue($row, 'nonce'),
                $this->binaryValue($row, 'ciphertext'),
            ),
            $this->dateValue($row, 'created_at'),
            $this->dateValue($row, 'expires_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Column "%s" is not a string.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nullableStringValue(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if (null === $value) {
            return null;
        }

        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Column "%s" is not a string.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function binaryValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            if (false === $contents) {
                throw new RuntimeException(sprintf('Column "%s" stream could not be read.', $key));
            }

            return $contents;
        }

        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Column "%s" is not binary.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function dateValue(array $row, string $key): DateTimeImmutable
    {
        return new DateTimeImmutable($this->stringValue($row, $key));
    }
}
