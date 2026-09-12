<?php

declare(strict_types=1);

namespace App\Secret\Application\RevealSecret;

use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Repository\SecretRepository;
use Psr\Clock\ClockInterface;

final readonly class LoadRevealSecretHandler
{
    public function __construct(
        private SecretRepository $secretRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(LoadRevealSecret $query): RevealSecretView|NotFound
    {
        $secret = $this->secretRepository->findByToken(new Token($query->token));
        if (null === $secret || $secret->isExpiredAt($this->clock->now())) {
            return NotFound::notFound();
        }

        return new RevealSecretView($secret->hint()?->value);
    }
}
