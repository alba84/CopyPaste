<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Domain\Service;

use App\Secret\Domain\Exception\TokenGenerationFailed;
use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\Token;
use App\Secret\Domain\Model\Ttl;
use App\Secret\Domain\Service\UniqueTokenGenerator;
use App\Tests\Support\Secret\FixedTokenGenerator;
use App\Tests\Support\Secret\InMemorySecretRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class UniqueTokenGeneratorTest extends TestCase
{
    public function testReturnsFirstTokenWhenItIsFree(): void
    {
        $free = $this->token(1);
        $generator = new UniqueTokenGenerator(new FixedTokenGenerator([$free]), new InMemorySecretRepository());

        self::assertSame($free, $generator->generate());
    }

    public function testReturnsNextTokenAfterCollision(): void
    {
        $busy = $this->token(1);
        $free = $this->token(2);
        $repository = new InMemorySecretRepository();
        $this->seed($repository, $busy);

        $generator = new UniqueTokenGenerator(new FixedTokenGenerator([$busy, $free]), $repository);

        self::assertSame($free, $generator->generate());
    }

    public function testFailsWhenEveryAttemptCollides(): void
    {
        $tokens = [$this->token(1), $this->token(2), $this->token(3), $this->token(4), $this->token(5)];
        $repository = new InMemorySecretRepository();
        $this->seed($repository, ...$tokens);

        $generator = new UniqueTokenGenerator(new FixedTokenGenerator($tokens), $repository);

        $this->expectException(TokenGenerationFailed::class);
        $generator->generate();
    }

    private function token(int $seed): Token
    {
        return new Token(str_pad((string) $seed, 32, '0', STR_PAD_LEFT));
    }

    private function seed(InMemorySecretRepository $repository, Token ...$tokens): void
    {
        $payload = new EncryptedPayload(str_repeat('s', 16), str_repeat('n', 24), 'ciphertext');
        foreach ($tokens as $token) {
            $repository->save(Secret::create($token, null, $payload, new DateTimeImmutable(), Ttl::fromDays(1)));
        }
    }
}
