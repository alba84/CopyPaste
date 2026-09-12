<?php

declare(strict_types=1);

namespace App\Secret\Application\CreateSecret;

use App\Crypto\Domain\Cryptography;
use App\Secret\Domain\Model\Hint;
use App\Secret\Domain\Model\Plaintext;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\SecretKey;
use App\Secret\Domain\Model\Ttl;
use App\Secret\Domain\Repository\SecretRepository;
use App\Secret\Domain\Service\UniqueTokenGenerator;
use Psr\Clock\ClockInterface;

final readonly class CreateSecretHandler
{
    public function __construct(
        private Cryptography $cryptography,
        private UniqueTokenGenerator $tokenGenerator,
        private SecretRepository $secretRepository,
        private ClockInterface $clock,
        private Ttl $ttl,
    ) {
    }

    public function __invoke(CreateSecret $command): CreateSecretResult
    {
        $token = $this->tokenGenerator->generate();
        $payload = $this->cryptography->encrypt(
            Plaintext::fromString($command->plaintext),
            SecretKey::fromString($command->secretKey),
        );
        $secret = Secret::create(
            $token,
            Hint::fromUserInput($command->hint),
            $payload,
            $this->clock->now(),
            $this->ttl,
        );
        $this->secretRepository->save($secret);

        return new CreateSecretResult($token);
    }
}
