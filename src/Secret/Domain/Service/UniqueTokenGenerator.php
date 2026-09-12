<?php

declare(strict_types=1);

namespace App\Secret\Domain\Service;

use App\Secret\Domain\Exception\TokenGenerationFailed;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Repository\SecretRepository;

final readonly class UniqueTokenGenerator
{
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private TokenGenerator $tokenGenerator,
        private SecretRepository $secretRepository,
    ) {
    }

    public function generate(): Token
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $token = $this->tokenGenerator->generate();
            if (!$this->secretRepository->tokenExists($token)) {
                return $token;
            }
        }

        throw new TokenGenerationFailed('Unable to generate a unique token after 5 attempts.');
    }
}
