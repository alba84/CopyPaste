<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Application\RevealSecret;

use App\Secret\Application\RevealSecret\LoadRevealSecret;
use App\Secret\Application\RevealSecret\LoadRevealSecretHandler;
use App\Secret\Application\RevealSecret\NotFound;
use App\Secret\Application\RevealSecret\RevealSecretView;
use App\Secret\Domain\Model\EncryptedPayload;
use App\Secret\Domain\Model\Hint;
use App\Secret\Domain\Model\Secret;
use App\Secret\Domain\Model\Token;
use App\Tests\Support\Secret\FixedClock;
use App\Tests\Support\Secret\InMemorySecretRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LoadRevealSecretHandlerTest extends TestCase
{
    private const string TOKEN = '0123456789abcdef0123456789abcdef';
    private const string NOW = '2026-03-01 10:00:00';

    public function testReturnsHintForActiveSecret(): void
    {
        $repository = $this->repositoryWith($this->secret(new DateTimeImmutable(self::NOW.'+1 second'), 'публичная подсказка'));

        $result = $this->handler($repository)(new LoadRevealSecret(self::TOKEN));

        self::assertInstanceOf(RevealSecretView::class, $result);
        self::assertSame('публичная подсказка', $result->hint);
    }

    public function testReturnsNullHintWhenSecretHasNoHint(): void
    {
        $repository = $this->repositoryWith($this->secret(new DateTimeImmutable(self::NOW.'+1 second'), null));

        $result = $this->handler($repository)(new LoadRevealSecret(self::TOKEN));

        self::assertInstanceOf(RevealSecretView::class, $result);
        self::assertNull($result->hint);
    }

    public function testMissingSecretYieldsNotFound(): void
    {
        $result = $this->handler(new InMemorySecretRepository())(new LoadRevealSecret(self::TOKEN));

        self::assertInstanceOf(NotFound::class, $result);
    }

    /** @return iterable<string, array{string}> */
    public static function expiredMoments(): iterable
    {
        yield 'before now' => ['2026-03-01 09:59:59'];
        yield 'exactly now' => [self::NOW];
    }

    #[DataProvider('expiredMoments')]
    public function testExpiredSecretYieldsNotFound(string $expiresAt): void
    {
        $repository = $this->repositoryWith($this->secret(new DateTimeImmutable($expiresAt), 'подсказка'));

        $result = $this->handler($repository)(new LoadRevealSecret(self::TOKEN));

        self::assertInstanceOf(NotFound::class, $result);
    }

    private function handler(InMemorySecretRepository $repository): LoadRevealSecretHandler
    {
        return new LoadRevealSecretHandler($repository, new FixedClock(new DateTimeImmutable(self::NOW)));
    }

    private function repositoryWith(Secret $secret): InMemorySecretRepository
    {
        $repository = new InMemorySecretRepository();
        $repository->save($secret);

        return $repository;
    }

    private function secret(DateTimeImmutable $expiresAt, ?string $hint): Secret
    {
        return Secret::reconstitute(
            new Token(self::TOKEN),
            null === $hint ? null : Hint::fromUserInput($hint),
            new EncryptedPayload(str_repeat('s', 16), str_repeat('n', 24), 'ciphertext'),
            new DateTimeImmutable('2026-02-01 10:00:00'),
            $expiresAt,
        );
    }
}
