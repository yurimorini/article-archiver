<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Kernel;

/**
 * Boots Eleanor's kernel and reads the environment the test run set.
 */
final class KernelTest extends TestCase
{
    public function test_boot_sees_test_environment(): void
    {
        $environment = $_SERVER['APP_ENV'] ?? null;
        if (!is_string($environment)) {
            self::fail('APP_ENV must be a string.');
        }

        $kernel = new Kernel($environment, true);
        $kernel->boot();

        self::assertSame('test', $kernel->getEnvironment());

        $kernel->shutdown();
    }
}
