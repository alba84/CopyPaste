<?php

declare(strict_types=1);

namespace App\Access\Infrastructure\Symfony;

use App\Access\Domain\AccessDecision;
use App\Access\Domain\AccessLimit;
use App\Access\Domain\AccessLimiter;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class SymfonyAccessLimiter implements AccessLimiter
{
    public function __construct(
        private RateLimiterFactory $createSecretLimiter,
        private RateLimiterFactory $revealSecretLimiter,
    ) {
    }

    public function consume(AccessLimit $limit, string $key): AccessDecision
    {
        $accepted = $this->factoryFor($limit)->create($key)->consume()->isAccepted();

        return $accepted ? AccessDecision::Accepted : AccessDecision::Rejected;
    }

    private function factoryFor(AccessLimit $limit): RateLimiterFactory
    {
        return match ($limit) {
            AccessLimit::CreateSecret => $this->createSecretLimiter,
            AccessLimit::RevealSecret => $this->revealSecretLimiter,
        };
    }
}
