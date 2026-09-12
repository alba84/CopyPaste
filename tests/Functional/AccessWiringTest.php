<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Access\Domain\AccessLimiter;
use App\Access\Infrastructure\Symfony\SymfonyAccessLimiter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

final class AccessWiringTest extends KernelTestCase
{
    public function testContainerResolvesAccessLimiterPortToSymfonyAdapter(): void
    {
        self::bootKernel();

        self::assertInstanceOf(SymfonyAccessLimiter::class, self::getContainer()->get(AccessLimiter::class));
    }

    public function testRateLimiterConfigurationKeepsBothFixedWindowLimits(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2).'/config/packages/rate_limiter.yaml');

        self::assertSame([
            'framework' => [
                'rate_limiter' => [
                    'create_paste' => [
                        'policy' => 'fixed_window',
                        'limit' => 10,
                        'interval' => '1 minute',
                        'cache_pool' => 'cache.app',
                    ],
                    'unlock_paste' => [
                        'policy' => 'fixed_window',
                        'limit' => 10,
                        'interval' => '1 minute',
                        'cache_pool' => 'cache.app',
                    ],
                ],
            ],
        ], $config);
    }
}
