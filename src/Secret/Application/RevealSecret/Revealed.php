<?php

declare(strict_types=1);

namespace App\Secret\Application\RevealSecret;

final readonly class Revealed implements RevealSecretResult
{
    private function __construct(public string $plaintext)
    {
    }

    public static function fromPlaintext(string $plaintext): self
    {
        return new self($plaintext);
    }
}
