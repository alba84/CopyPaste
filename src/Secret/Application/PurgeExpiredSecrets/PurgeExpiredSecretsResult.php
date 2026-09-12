<?php

declare(strict_types=1);

namespace App\Secret\Application\PurgeExpiredSecrets;

final readonly class PurgeExpiredSecretsResult
{
    public function __construct(public int $deletedCount)
    {
    }
}
