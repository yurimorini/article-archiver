<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Probe;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Probe\ProbeRun;

/**
 * Proves a broken probe names the reason on stderr.
 */
final class ProbeRunTest extends TestCase
{
    public function test_unknown_step_writes_the_reason_and_returns_1(): void
    {
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);

        $exit = ProbeRun::main(['not-a-step'], $stderr);

        self::assertSame(1, $exit);
        rewind($stderr);
        self::assertSame("Unknown probe step: not-a-step\n", stream_get_contents($stderr));
    }

    public function test_empty_token_writes_the_reason_and_returns_1(): void
    {
        $previous = getenv('ELEANOR_BEARER_TOKEN');
        putenv('ELEANOR_BEARER_TOKEN');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);

        try {
            $exit = ProbeRun::main(['invalid-json'], $stderr);
        } finally {
            if (is_string($previous)) {
                putenv('ELEANOR_BEARER_TOKEN=' . $previous);
            }
        }

        self::assertSame(1, $exit);
        rewind($stderr);
        self::assertSame("The bearer token is empty.\n", stream_get_contents($stderr));
    }

    public function test_success_writes_the_run_folder(): void
    {
        $stdout = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);

        $exit = ProbeRun::finish(0, '/pkg/probe/runs/20261101T210000Z-7', '/pkg', $stdout);

        self::assertSame(0, $exit);
        rewind($stdout);
        self::assertSame("Wrote probe/runs/20261101T210000Z-7\n", stream_get_contents($stdout));
    }

    public function test_failure_writes_nothing_on_success_output(): void
    {
        $stdout = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);

        $exit = ProbeRun::finish(1, '/pkg/probe/runs/20261101T210000Z-7', '/pkg', $stdout);

        self::assertSame(1, $exit);
        rewind($stdout);
        self::assertSame('', stream_get_contents($stdout));
    }
}
