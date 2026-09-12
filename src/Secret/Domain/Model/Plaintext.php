<?php

declare(strict_types=1);

namespace App\Secret\Domain\Model;

use App\Secret\Domain\Exception\InvalidPlaintext;

final readonly class Plaintext
{
    private const int MAX_LENGTH = 100000;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if ('' === $value) {
            throw new InvalidPlaintext('Plaintext must not be empty.');
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidPlaintext('Plaintext must not exceed 100000 characters.');
        }

        return new self($value);
    }
}
