<?php

declare(strict_types=1);

namespace App\Secret\Domain\Model;

use App\Secret\Domain\Exception\InvalidToken;

final readonly class Token
{
    private const string FORMAT = '/^[a-f0-9]{32}$/';

    public function __construct(public string $value)
    {
        if (1 !== preg_match(self::FORMAT, $value)) {
            throw new InvalidToken('Token must be 32 lowercase hexadecimal characters.');
        }
    }
}
