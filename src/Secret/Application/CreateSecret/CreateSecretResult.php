<?php

declare(strict_types=1);

namespace App\Secret\Application\CreateSecret;

use App\Secret\Domain\Model\Token;

final readonly class CreateSecretResult
{
    public function __construct(public Token $token)
    {
    }
}
