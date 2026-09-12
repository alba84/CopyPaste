<?php

declare(strict_types=1);

namespace App\Secret\Domain\Model;

use App\Secret\Domain\Exception\InvalidHint;

final readonly class Hint
{
    private const int MAX_LENGTH = 255;

    private function __construct(public string $value)
    {
    }

    public static function fromUserInput(?string $input): ?self
    {
        if (null === $input) {
            return null;
        }

        if ('' === trim($input)) {
            return null;
        }

        if (mb_strlen($input) > self::MAX_LENGTH) {
            throw new InvalidHint('Hint must not exceed 255 characters.');
        }

        return new self($input);
    }
}
