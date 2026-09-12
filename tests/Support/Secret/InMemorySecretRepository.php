<?php

declare(strict_types=1);

namespace App\Tests\Support\Secret;

use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Repository\SecretRepository;
use DateTimeImmutable;

final class InMemorySecretRepository implements SecretRepository
{
    /** @var array<string, Secret> */
    private array $secrets = [];

    private ?DateTimeImmutable $lastRemovedBefore = null;

    public function save(Secret $secret): void
    {
        $this->secrets[$secret->token()->value] = $secret;
    }

    public function findByToken(Token $token): ?Secret
    {
        return $this->secrets[$token->value] ?? null;
    }

    public function tokenExists(Token $token): bool
    {
        return isset($this->secrets[$token->value]);
    }

    public function removeExpiredBefore(DateTimeImmutable $now): int
    {
        $this->lastRemovedBefore = $now;
        $removed = 0;
        foreach ($this->secrets as $token => $secret) {
            if ($secret->expiresAt() <= $now) {
                unset($this->secrets[$token]);
                ++$removed;
            }
        }

        return $removed;
    }

    public function lastRemovedBefore(): ?DateTimeImmutable
    {
        return $this->lastRemovedBefore;
    }

    public function count(): int
    {
        return count($this->secrets);
    }
}
