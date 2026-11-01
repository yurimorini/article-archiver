<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Probe;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Probe\LogTail;
use Yumo\Eleanor\Probe\Record;

/**
 * Proves that a run writes step JSON without a secret and tails matching request lines.
 */
final class RecordTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/eleanor-probe-record-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function test_writes_run_and_step_json_without_a_secret(): void
    {
        $record = new Record($this->directory);
        $record->writeRun(['id' => 'run-1']);
        $record->writeStep(2, 'archive', [
            'authorization' => 'bearer',
            'request' => ['body' => '{"url":"https://example.com/a"}'],
        ]);

        $runFile = $this->directory . '/run.json';
        self::assertFileExists($runFile);
        self::assertStringContainsString('run-1', (string) file_get_contents($runFile));

        $stepFile = $this->directory . '/steps/02-archive.json';
        self::assertFileExists($stepFile);
        self::assertStringNotContainsString('secret-token', (string) file_get_contents($stepFile));
    }

    public function test_for_request_returns_only_the_matching_json_object(): void
    {
        $match = [
            'message' => 'http.request.finish',
            'extra' => ['request_id' => 'req-1'],
        ];
        $other = [
            'message' => 'http.request.finish',
            'extra' => ['request_id' => 'req-2'],
        ];

        $found = LogTail::forRequest(
            [
                json_encode($match, JSON_THROW_ON_ERROR),
                'not-json',
                json_encode($other, JSON_THROW_ON_ERROR),
            ],
            'req-1',
        );

        self::assertSame([$match], $found);
    }
}
