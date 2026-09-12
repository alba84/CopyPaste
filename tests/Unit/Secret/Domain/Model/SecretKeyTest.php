<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Domain\Model;

use App\Secret\Domain\Exception\InvalidSecretKey;
use App\Secret\Domain\Model\SecretKey;
use PHPUnit\Framework\TestCase;

final class SecretKeyTest extends TestCase
{
    public function testAcceptsNonEmptyKey(): void
    {
        $secretKey = SecretKey::fromString('корректный ключ');

        self::assertSame('корректный ключ', $secretKey->value);
    }

    public function testAcceptsKeyOfExactlyMaximumLength(): void
    {
        $value = str_repeat('k', 255);

        self::assertSame($value, SecretKey::fromString($value)->value);
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(InvalidSecretKey::class);

        SecretKey::fromString('');
    }

    public function testRejectsKeyLongerThanMaximum(): void
    {
        $this->expectException(InvalidSecretKey::class);

        SecretKey::fromString(str_repeat('k', 256));
    }
}
