<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Domain\Model;

use App\Secret\Domain\Exception\InvalidToken;
use App\Secret\Domain\Model\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenTest extends TestCase
{
    public function testAccepts32LowercaseHexCharacters(): void
    {
        $token = new Token('0123456789abcdef0123456789abcdef');

        self::assertSame('0123456789abcdef0123456789abcdef', $token->value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokens(): iterable
    {
        yield 'uppercase' => ['0123456789ABCDEF0123456789ABCDEF'];
        yield 'too short' => ['0123456789abcdef0123456789abcde'];
        yield 'too long' => ['0123456789abcdef0123456789abcdef0'];
        yield 'non-hex' => ['0123456789abcdef0123456789abcdeg'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidTokens')]
    public function testRejectsInvalidToken(string $value): void
    {
        $this->expectException(InvalidToken::class);

        new Token($value);
    }
}
