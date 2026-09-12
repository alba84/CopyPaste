<?php

declare(strict_types=1);

namespace App\Secret\Domain\Model;

use App\Secret\Domain\Event\SecretCreated;
use App\Secret\Domain\Event\SecretDomainEvent;
use DateTimeImmutable;

final class Secret
{
    /** @var list<SecretDomainEvent> */
    private array $domainEvents = [];

    private function __construct(
        private readonly Token $token,
        private readonly ?Hint $hint,
        private readonly EncryptedPayload $payload,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $expiresAt,
    ) {
    }

    public static function create(Token $token, ?Hint $hint, EncryptedPayload $payload, DateTimeImmutable $createdAt, Ttl $ttl): self
    {
        $secret = new self($token, $hint, $payload, $createdAt, $ttl->expiresAtFrom($createdAt));
        $secret->domainEvents[] = new SecretCreated($token, $createdAt);

        return $secret;
    }

    public static function reconstitute(Token $token, ?Hint $hint, EncryptedPayload $payload, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt): self
    {
        return new self($token, $hint, $payload, $createdAt, $expiresAt);
    }

    public function token(): Token
    {
        return $this->token;
    }

    public function hint(): ?Hint
    {
        return $this->hint;
    }

    public function payload(): EncryptedPayload
    {
        return $this->payload;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /** @return list<SecretDomainEvent> */
    public function pullDomainEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }
}
