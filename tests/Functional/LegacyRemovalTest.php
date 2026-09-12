<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyRemovalTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function removedClasses(): iterable
    {
        yield 'PasteController' => ['App\\Controller\\PasteController'];
        yield 'CreatePasteData' => ['App\\Dto\\CreatePasteData'];
        yield 'UnlockPasteData' => ['App\\Dto\\UnlockPasteData'];
        yield 'EncryptedPayload' => ['App\\Dto\\EncryptedPayload'];
        yield 'CreatePasteType' => ['App\\Form\\CreatePasteType'];
        yield 'UnlockPasteType' => ['App\\Form\\UnlockPasteType'];
        yield 'EncryptionService' => ['App\\Service\\EncryptionService'];
        yield 'PasteEntity' => ['App\\Entity\\Paste'];
        yield 'PasteRepository' => ['App\\Repository\\PasteRepository'];
    }

    #[DataProvider('removedClasses')]
    public function testLegacyClassIsRemoved(string $class): void
    {
        self::assertFalse(class_exists($class), sprintf('Legacy class "%s" still exists.', $class));
    }

    public function testTestOnlyServicesConfigIsRemoved(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 2).'/config/services_test.yaml');
    }
}
