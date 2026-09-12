<?php

declare(strict_types=1);

namespace App\Secret\Domain\Model;

use App\Secret\Domain\Exception\InvalidSecretKey;

final readonly class SecretKey
{
    private const int MAX_LENGTH = 255;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if ('' === $value) {
            throw new InvalidSecretKey('Secret key must not be empty.');
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidSecretKey('Secret key must not exceed 255 characters.');
        }

        return new self($value);
    }
}
