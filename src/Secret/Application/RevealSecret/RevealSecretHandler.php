<?php

declare(strict_types=1);

namespace App\Secret\Application\RevealSecret;

use App\Crypto\Domain\Cryptography;
use App\Crypto\Domain\DecryptedPlaintext;
use App\Secret\Domain\Model\SecretKey;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Repository\SecretRepository;
use Psr\Clock\ClockInterface;

final readonly class RevealSecretHandler
{
    public function __construct(
        private SecretRepository $secretRepository,
        private Cryptography $cryptography,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RevealSecret $command): RevealSecretResult
    {
        $secret = $this->secretRepository->findByToken(new Token($command->token));
        if (null === $secret || $secret->isExpiredAt($this->clock->now())) {
            return NotFound::notFound();
        }

        $decryption = $this->cryptography->decrypt(
            $secret->payload(),
            SecretKey::fromString($command->secretKey),
        );

        if ($decryption instanceof DecryptedPlaintext) {
            return Revealed::fromPlaintext($decryption->plaintext);
        }

        return InvalidKey::invalidKey();
    }
}
