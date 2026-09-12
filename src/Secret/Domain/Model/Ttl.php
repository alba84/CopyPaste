<?php

declare(strict_types=1);

namespace App\Secret\Domain\Model;

use App\Secret\Domain\Exception\InvalidTtl;
use DateInterval;
use DateTimeImmutable;

final readonly class Ttl
{
    private function __construct(public int $days)
    {
    }

    public static function fromDays(int $days): self
    {
        if ($days < 1) {
            throw new InvalidTtl('Ttl must be at least one day.');
        }

        return new self($days);
    }

    public function expiresAtFrom(DateTimeImmutable $createdAt): DateTimeImmutable
    {
        return $createdAt->add(new DateInterval('P'.$this->days.'D'));
    }
}
