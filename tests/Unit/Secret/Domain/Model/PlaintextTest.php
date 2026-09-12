<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Domain\Model;

use App\Secret\Domain\Exception\InvalidPlaintext;
use App\Secret\Domain\Model\Plaintext;
use PHPUnit\Framework\TestCase;

final class PlaintextTest extends TestCase
{
    public function testAcceptsNonEmptyText(): void
    {
        $plaintext = Plaintext::fromString('Секретный текст');

        self::assertSame('Секретный текст', $plaintext->value);
    }

    public function testAcceptsTextOfExactlyMaximumLength(): void
    {
        $value = str_repeat('x', 100000);

        self::assertSame($value, Plaintext::fromString($value)->value);
    }

    public function testRejectsEmptyText(): void
    {
        $this->expectException(InvalidPlaintext::class);

        Plaintext::fromString('');
    }

    public function testRejectsTextLongerThanMaximum(): void
    {
        $this->expectException(InvalidPlaintext::class);

        Plaintext::fromString(str_repeat('x', 100001));
    }
}
