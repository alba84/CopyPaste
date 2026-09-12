<?php

declare(strict_types=1);

namespace App\Secret\Infrastructure\Token;

use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Service\TokenGenerator;

final readonly class RandomTokenGenerator implements TokenGenerator
{
    private const int BYTES = 16;

    public function generate(): Token
    {
        return new Token(bin2hex(random_bytes(self::BYTES)));
    }
}
