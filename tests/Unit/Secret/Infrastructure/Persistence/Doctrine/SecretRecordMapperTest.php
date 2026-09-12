<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Infrastructure\Persistence\Doctrine;

use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Hint;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Model\Ttl;
use App\Secret\Infrastructure\Persistence\Doctrine\SecretRecord;
use App\Secret\Infrastructure\Persistence\Doctrine\SecretRecordMapper;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecretRecordMapperTest extends TestCase
{
    private const string TOKEN = '0123456789abcdef0123456789abcdef';
    private const string SALT = '0123456789abcdef';
    private const string NONCE = '0123456789abcdef01234567';

    public function testToRecordMapsAggregateToColumns(): void
    {
        $createdAt = new DateTimeImmutable('2026-04-01 09:00:00');
        $secret = Secret::create(new Token(self::TOKEN), $this->hint(), $this->payload(), $createdAt, Ttl::fromDays(7));

        $record = (new SecretRecordMapper())->toRecord($secret);

        self::assertSame(self::TOKEN, $record->token);
        self::assertSame('подсказка', $record->hint);
        self::assertSame(self::SALT, $record->salt);
        self::assertSame(self::NONCE, $record->nonce);
        self::assertSame('ciphertext', $record->ciphertext);
        self::assertSame($createdAt, $record->createdAt);
        self::assertSame($createdAt->add(new DateInterval('P7D'))->format('Y-m-d H:i:s'), $record->expiresAt->format('Y-m-d H:i:s'));
    }

    public function testFromRowReconstitutesAggregateFromStringBlobs(): void
    {
        $secret = (new SecretRecordMapper())->fromRow([
            'token' => self::TOKEN,
            'hint' => 'подсказка',
            'salt' => self::SALT,
            'nonce' => self::NONCE,
            'ciphertext' => 'ciphertext',
            'created_at' => '2026-04-01 09:00:00',
            'expires_at' => '2026-04-08 09:00:00',
        ]);

        self::assertSame(self::TOKEN, $secret->token()->value);
        self::assertSame('подсказка', $secret->hint()?->value);
        self::assertSame(self::SALT, $secret->payload()->salt);
        self::assertSame('2026-04-01 09:00:00', $secret->createdAt()->format('Y-m-d H:i:s'));
        self::assertSame([], $secret->pullDomainEvents());
    }

    public function testFromRowReadsResourceBlobs(): void
    {
        $secret = (new SecretRecordMapper())->fromRow([
            'token' => self::TOKEN,
            'hint' => null,
            'salt' => $this->stream(self::SALT),
            'nonce' => $this->stream(self::NONCE),
            'ciphertext' => $this->stream('ciphertext'),
            'created_at' => '2026-04-01 09:00:00',
            'expires_at' => '2026-04-08 09:00:00',
        ]);

        self::assertSame(self::SALT, $secret->payload()->salt);
        self::assertSame(self::NONCE, $secret->payload()->nonce);
        self::assertSame('ciphertext', $secret->payload()->ciphertext);
        self::assertNull($secret->hint());
    }

    public function testFromRowRejectsNonStringToken(): void
    {
        $this->expectException(RuntimeException::class);

        (new SecretRecordMapper())->fromRow([
            'token' => 123,
            'hint' => null,
            'salt' => self::SALT,
            'nonce' => self::NONCE,
            'ciphertext' => 'ciphertext',
            'created_at' => '2026-04-01 09:00:00',
            'expires_at' => '2026-04-08 09:00:00',
        ]);
    }

    public function testRecordIsImmutableValueObject(): void
    {
        $now = new DateTimeImmutable();
        $record = new SecretRecord(self::TOKEN, null, self::SALT, self::NONCE, 'ciphertext', $now, $now);

        self::assertSame(self::TOKEN, $record->token);
    }

    private function hint(): Hint
    {
        $hint = Hint::fromUserInput('подсказка');
        self::assertNotNull($hint);

        return $hint;
    }

    private function payload(): EncryptedPayload
    {
        return new EncryptedPayload(self::SALT, self::NONCE, 'ciphertext');
    }

    /** @return resource */
    private function stream(string $contents)
    {
        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new RuntimeException('Unable to open temporary stream.');
        }
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
