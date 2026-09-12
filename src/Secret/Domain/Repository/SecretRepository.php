<?php

declare(strict_types=1);

namespace App\Secret\Domain\Repository;

use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\Token;
use DateTimeImmutable;

interface SecretRepository
{
    public function save(Secret $secret): void;

    public function findByToken(Token $token): ?Secret;

    public function tokenExists(Token $token): bool;

    public function removeExpiredBefore(DateTimeImmutable $now): int;
}
