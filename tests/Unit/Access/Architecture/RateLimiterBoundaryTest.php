<?php

declare(strict_types=1);

namespace App\Tests\Unit\Access\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

final class RateLimiterBoundaryTest extends TestCase
{
    private const string SRC = __DIR__.'/../../../../src';

    public function testRateLimiterNamespaceIsConfinedToAccessInfrastructure(): void
    {
        $matches = 0;

        foreach ($this->sourceFiles() as $relativePath => $contents) {
            if (!str_contains($contents, 'Symfony\\Component\\RateLimiter')) {
                continue;
            }

            ++$matches;
            self::assertStringStartsWith(
                'Access/Infrastructure/',
                $relativePath,
                'Symfony RateLimiter may only be referenced from Access/Infrastructure.',
            );
        }

        self::assertGreaterThan(0, $matches, 'Expected the Access Symfony adapter to reference Symfony RateLimiter.');
    }

    public function testAccessDomainIsFrameworkFree(): void
    {
        $scanned = 0;

        foreach ($this->sourceFiles() as $relativePath => $contents) {
            if (!str_starts_with($relativePath, 'Access/Domain/')) {
                continue;
            }

            ++$scanned;
            self::assertStringNotContainsString('Symfony\\', $contents);
            self::assertStringNotContainsString('Doctrine\\', $contents);
            self::assertStringNotContainsString('sodium_', $contents);
        }

        self::assertGreaterThan(0, $scanned, 'Expected Access/Domain to contain the rate limiting port.');
    }

    public function testSecretLayersDoNotDependOnAccessInfrastructure(): void
    {
        foreach ($this->sourceFiles() as $relativePath => $contents) {
            if (!str_starts_with($relativePath, 'Secret/')) {
                continue;
            }

            self::assertStringNotContainsString('App\\Access\\Infrastructure', $contents);

            if (str_starts_with($relativePath, 'Secret/Domain/') || str_starts_with($relativePath, 'Secret/Application/')) {
                self::assertStringNotContainsString('App\\Access', $contents);
            }
        }
    }

    /**
     * @return iterable<string, string>
     */
    private function sourceFiles(): iterable
    {
        $sourceRoot = realpath(self::SRC);
        self::assertIsString($sourceRoot);

        $files = Finder::create()->files()->in($sourceRoot)->name('*.php')->sortByName();

        $prefixLength = strlen($sourceRoot) + 1;
        foreach ($files as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            $realPath = $file->getRealPath();
            self::assertIsString($realPath);

            yield substr($realPath, $prefixLength) => (string) file_get_contents($realPath);
        }
    }
}
