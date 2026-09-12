<?php

declare(strict_types=1);

namespace App\Tests\Unit\Secret\Domain\Model;

use App\Secret\Domain\Exception\InvalidHint;
use App\Secret\Domain\Model\Hint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HintTest extends TestCase
{
    /** @return iterable<string, array{?string}> */
    public static function blankHints(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
    }

    #[DataProvider('blankHints')]
    public function testBlankHintMeansNoHint(?string $input): void
    {
        self::assertNull(Hint::fromUserInput($input));
    }

    public function testNonBlankHintIsStoredVerbatim(): void
    {
        $hint = Hint::fromUserInput(' подсказка ');

        self::assertInstanceOf(Hint::class, $hint);
        self::assertSame(' подсказка ', $hint->value);
    }

    public function testHintOfExactly255CharactersIsAccepted(): void
    {
        $value = str_repeat('x', 255);
        $hint = Hint::fromUserInput($value);

        self::assertInstanceOf(Hint::class, $hint);
        self::assertSame($value, $hint->value);
    }

    public function testHintLongerThan255CharactersIsRejected(): void
    {
        $this->expectException(InvalidHint::class);

        Hint::fromUserInput(str_repeat('x', 256));
    }
}
