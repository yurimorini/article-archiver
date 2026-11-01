<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Probe;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Probe\Config;
use Yumo\Eleanor\Probe\Guard;

/**
 * Proves that a probe refuses an empty token, a mismatched owner, and a broken config.
 */
final class GuardTest extends TestCase
{
    public function test_rejects_an_empty_token(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Guard::token('');
    }

    public function test_lowercases_the_parameter_email_when_the_configured_owner_matches(): void
    {
        self::assertSame('owner@example.com', Guard::owner('Owner@Example.com', 'owner@example.com'));
    }

    public function test_uses_the_parameter_email_when_the_config_has_no_owner(): void
    {
        self::assertSame('owner@example.com', Guard::owner('Owner@Example.com', null));
    }

    public function test_rejects_a_configured_owner_that_differs_from_the_parameter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Guard::owner('owner@example.com', 'ada@example.com');
    }

    public function test_fills_sample_port_and_seed_when_the_file_only_lists_steps(): void
    {
        $path = $this->writeConfig('{"steps":["list"]}');

        try {
            $config = Config::fromFile($path);

            self::assertSame('var/log-urls.txt', $config->urls);
            self::assertSame(10, $config->sample);
            self::assertNull($config->owner);
            self::assertSame(8000, $config->port);
            self::assertNull($config->seed);
            self::assertSame(['list'], $config->steps);
        } finally {
            unlink($path);
        }
    }

    public function test_rejects_a_sample_below_one(): void
    {
        $path = $this->writeConfig('{"sample":0,"steps":["list"]}');

        try {
            $this->expectException(\InvalidArgumentException::class);

            Config::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    public function test_rejects_a_sample_above_ten(): void
    {
        $path = $this->writeConfig('{"sample":11,"steps":["list"]}');

        try {
            $this->expectException(\InvalidArgumentException::class);

            Config::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    public function test_rejects_a_config_that_omits_steps(): void
    {
        $path = $this->writeConfig('{}');

        try {
            $this->expectException(\InvalidArgumentException::class);

            Config::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    private function writeConfig(string $json): string
    {
        $path = tempnam(sys_get_temp_dir(), 'probe-config-');
        if ($path === false) {
            throw new \RuntimeException('Could not create a temp config.');
        }

        file_put_contents($path, $json);

        return $path;
    }
}
