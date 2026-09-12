<?php

declare(strict_types=1);

namespace App\Secret\Application\RevealSecret;

final readonly class LoadRevealSecret
{
    public function __construct(public string $token)
    {
    }
}
