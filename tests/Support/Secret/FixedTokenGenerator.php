<?php

declare(strict_types=1);

namespace App\Tests\Support\Secret;

use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Service\TokenGenerator;
use RuntimeException;

final class FixedTokenGenerator implements TokenGenerator
{
    /** @var list<Token> */
    private array $tokens;

    /** @param list<Token> $tokens */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    public function generate(): Token
    {
        $token = array_shift($this->tokens);
        if (!$token instanceof Token) {
            throw new RuntimeException('No more tokens configured.');
        }

        return $token;
    }
}
