<?php

declare(strict_types=1);

namespace App\Secret\Presentation\Http;

use DateTimeImmutable;

final readonly class SecretCreatedView
{
    public function __construct(public DateTimeImmutable $expiresAt)
    {
    }
}
