<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Infrastructure\Token;

use App\Secret\Infrastructure\Token\RandomTokenGenerator;
use PHPUnit\Framework\TestCase;

final class RandomTokenGeneratorTest extends TestCase
{
    public function testGeneratesDistinct32HexTokens(): void
    {
        $generator = new RandomTokenGenerator();

        $first = $generator->generate();
        $second = $generator->generate();

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first->value);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $second->value);
        self::assertNotSame($first->value, $second->value);
    }
}
