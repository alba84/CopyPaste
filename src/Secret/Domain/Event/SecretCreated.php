<?php

declare(strict_types=1);

namespace App\Secret\Domain\Event;

use App\Secret\Domain\Model\Token;
use DateTimeImmutable;

final readonly class SecretCreated implements SecretDomainEvent
{
    public function __construct(
        public Token $token,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
