<?php

declare(strict_types=1);

namespace App\Secret\Application\PurgeExpiredSecrets;

use App\Secret\Domain\Repository\SecretRepository;
use Psr\Clock\ClockInterface;

final readonly class PurgeExpiredSecretsHandler
{
    public function __construct(
        private SecretRepository $secretRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PurgeExpiredSecrets $command): PurgeExpiredSecretsResult
    {
        return new PurgeExpiredSecretsResult(
            $this->secretRepository->removeExpiredBefore($this->clock->now()),
        );
    }
}
