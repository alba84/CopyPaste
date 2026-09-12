<?php

declare(strict_types=1);

namespace App\Access\Domain;

interface AccessLimiter
{
    public function consume(AccessLimit $limit, string $key): AccessDecision;
}
