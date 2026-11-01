<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Probe;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Probe\Job;
use Yumo\Eleanor\Probe\Record;
use Yumo\Eleanor\Probe\Scheduler;
use Yumo\Eleanor\Probe\Settled;

/**
 * Proves the scheduler orders probe bands and writes each settled step.
 */
final class SchedulerTest extends TestCase
{
    private const string BASE_URL = 'http://127.0.0.1:8000';

    private const string ARCHIVE_URL = 'https://example.com/a';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/eleanor-probe-scheduler-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function test_starts_refusals_with_import_and_list_after_the_write_chain(): void
    {
        $io = new FakeIo();
        $io->script('server', $this->outcome('server', 'server'));
        $io->script('import', $this->outcome(
            'import',
            'import',
            exitCode: 0,
            stdout: "imported\n",
            stderr: '',
        ));
        $io->script('archive', $this->outcome(
            'http',
            'archive',
            status: 201,
            body: '{"id":1}',
            headers: ['content-type' => 'application/json', 'x-request-id' => 'req-archive'],
        ));
        $io->script('archive-again', $this->outcome(
            'http',
            'archive-again',
            status: 200,
            body: '{"id":1}',
            headers: ['content-type' => 'application/json'],
        ));
        $io->script('list', $this->outcome('http', 'list', status: 200, body: '[]'));
        $io->script('no-bearer', $this->outcome(
            'http',
            'no-bearer',
            status: 401,
            body: '{"code":"unauthenticated"}',
            headers: ['content-type' => 'application/json'],
        ));

        $exit = $this->scheduler($io)->run([
            'import',
            'archive',
            'archive-again',
            'list',
            'no-bearer',
        ]);

        self::assertSame(0, $exit);
        self::assertSame(1, $io->stops);
        self::assertFileDoesNotExist($this->directory . '/run.json');

        $polls = $this->pollsByName($io);
        $jobs = $this->jobsByName($io);
        self::assertSame(
            ['server', 'import', 'no-bearer', 'archive', 'archive-again', 'list'],
            array_keys($polls),
        );
        self::assertSame(0, $polls['server']);
        self::assertSame(1, $polls['import']);
        self::assertSame(1, $polls['no-bearer']);
        self::assertSame(2, $polls['archive']);
        self::assertSame(3, $polls['archive-again']);
        self::assertSame(4, $polls['list']);

        self::assertSame('server', $jobs['server']->kind);
        self::assertNull($jobs['server']->preset);
        self::assertNull($jobs['server']->url);
        self::assertSame('import', $jobs['import']->kind);
        self::assertNull($jobs['import']->preset);
        self::assertNull($jobs['import']->url);
        self::assertSame('http', $jobs['no-bearer']->kind);
        self::assertNull($jobs['no-bearer']->url);
        self::assertSame('https://example.com/a', $jobs['archive']->url);
        self::assertSame('https://example.com/a', $jobs['archive-again']->url);
        self::assertNull($jobs['list']->url);

        self::assertSame([
            'name' => 'import',
            'request' => null,
            'response' => null,
            'log' => null,
            'import' => [
                'exit' => 0,
                'stdout' => "imported\n",
                'stderr' => '',
                'errorLog' => null,
            ],
            'sql' => null,
            'error' => null,
        ], $this->readJson($this->directory . '/steps/01-import.json'));
        self::assertSame([
            'name' => 'archive',
            'request' => [
                'method' => 'POST',
                'url' => 'http://127.0.0.1:8000/api/archives',
                'authorization' => 'bearer',
                'contentType' => 'application/json',
                'body' => '{"url":"https://example.com/a"}',
            ],
            'response' => [
                'status' => 201,
                'headers' => [
                    'content-type' => 'application/json',
                    'x-request-id' => 'req-archive',
                ],
                'body' => '{"id":1}',
            ],
            'log' => [],
            'import' => null,
            'sql' => null,
            'error' => null,
        ], $this->readJson($this->directory . '/steps/02-archive.json'));
        self::assertSame([
            'name' => 'archive-again',
            'request' => [
                'method' => 'POST',
                'url' => 'http://127.0.0.1:8000/api/archives',
                'authorization' => 'bearer',
                'contentType' => 'application/json',
                'body' => '{"url":"https://example.com/a"}',
            ],
            'response' => [
                'status' => 200,
                'headers' => ['content-type' => 'application/json'],
                'body' => '{"id":1}',
            ],
            'log' => [],
            'import' => null,
            'sql' => null,
            'error' => null,
        ], $this->readJson($this->directory . '/steps/03-archive-again.json'));
        self::assertSame([
            'name' => 'list',
            'request' => [
                'method' => 'GET',
                'url' => 'http://127.0.0.1:8000/api/archives',
                'authorization' => 'bearer',
                'contentType' => null,
                'body' => null,
            ],
            'response' => [
                'status' => 200,
                'headers' => [],
                'body' => '[]',
            ],
            'log' => [],
            'import' => null,
            'sql' => null,
            'error' => null,
        ], $this->readJson($this->directory . '/steps/04-list.json'));
        self::assertSame([
            'name' => 'no-bearer',
            'request' => [
                'method' => 'POST',
                'url' => 'http://127.0.0.1:8000/api/archives',
                'authorization' => 'absent',
                'contentType' => 'application/json',
                'body' => '{"url":"https://example.com/"}',
            ],
            'response' => [
                'status' => 401,
                'headers' => ['content-type' => 'application/json'],
                'body' => '{"code":"unauthenticated"}',
            ],
            'log' => [],
            'import' => null,
            'sql' => null,
            'error' => null,
        ], $this->readJson($this->directory . '/steps/05-no-bearer.json'));
    }

    public function test_writes_import_error_before_archive_and_returns_failure(): void
    {
        $io = new FakeIo();
        $io->script('server', $this->outcome('server', 'server'));
        $io->script('import', $this->outcome('import', 'import', error: 'Import did not start.'));
        $io->script('archive', $this->outcome('http', 'archive', status: 201, body: '{"id":1}'));
        $io->onStart(function (Job $job): void {
            if ($job->name !== 'archive') {
                return;
            }

            self::assertFileExists($this->directory . '/steps/01-import.json');
        });

        $exit = $this->scheduler($io)->run(['import', 'archive']);

        self::assertSame(1, $exit);
        self::assertSame(1, $io->stops);
        self::assertSame(
            ['server', 'import', 'archive'],
            array_keys($this->pollsByName($io)),
        );
        self::assertSame([
            'name' => 'import',
            'request' => null,
            'response' => null,
            'log' => null,
            'import' => [
                'exit' => null,
                'stdout' => null,
                'stderr' => null,
                'errorLog' => null,
            ],
            'sql' => null,
            'error' => 'Import did not start.',
        ], $this->readJson($this->directory . '/steps/01-import.json'));
        self::assertNull($this->readJson($this->directory . '/steps/02-archive.json')['error']);
    }

    public function test_continues_after_archive_status_and_returns_success(): void
    {
        $io = new FakeIo();
        $io->script('server', $this->outcome('server', 'server'));
        $io->script('archive', $this->outcome(
            'http',
            'archive',
            status: 422,
            body: '{"code":"fetch_failed"}',
            headers: ['content-type' => 'application/json'],
        ));
        $io->script('archive-again', $this->outcome('http', 'archive-again', status: 200, body: '{"id":1}'));

        $exit = $this->scheduler($io)->run(['archive', 'archive-again']);

        self::assertSame(0, $exit);
        self::assertSame(1, $io->stops);
        $polls = $this->pollsByName($io);
        self::assertSame(
            ['server', 'archive', 'archive-again'],
            array_keys($polls),
        );
        self::assertSame(0, $polls['server']);
        self::assertSame(1, $polls['archive']);
        self::assertGreaterThan($polls['archive'], $polls['archive-again']);
        self::assertSame([
            'name' => 'archive',
            'request' => [
                'method' => 'POST',
                'url' => 'http://127.0.0.1:8000/api/archives',
                'authorization' => 'bearer',
                'contentType' => 'application/json',
                'body' => '{"url":"https://example.com/a"}',
            ],
            'response' => [
                'status' => 422,
                'headers' => ['content-type' => 'application/json'],
                'body' => '{"code":"fetch_failed"}',
            ],
            'log' => [],
            'import' => null,
            'sql' => null,
            'error' => null,
        ], $this->readJson($this->directory . '/steps/01-archive.json'));
    }

    public function test_stops_when_the_server_fails_and_writes_no_step(): void
    {
        $io = new FakeIo();
        $io->script('server', $this->outcome('server', 'server', error: 'The server did not answer.'));

        $exit = $this->scheduler($io)->run(['archive']);

        self::assertSame(1, $exit);
        self::assertSame(1, $io->stops);
        $kinds = [];
        foreach ($io->starts as $start) {
            $kinds[] = $start['job']->kind;
        }
        self::assertSame(['server'], $kinds);
        self::assertDirectoryDoesNotExist($this->directory . '/steps');
        self::assertFileDoesNotExist($this->directory . '/run.json');
    }

    private function scheduler(FakeIo $io): Scheduler
    {
        return new Scheduler($io, new Record($this->directory), self::BASE_URL, self::ARCHIVE_URL);
    }

    /**
     * @param array<string, string> $headers
     */
    private function outcome(
        string $kind,
        string $name,
        ?string $error = null,
        ?int $status = null,
        ?string $body = null,
        array $headers = [],
        ?int $exitCode = null,
        ?string $stdout = null,
        ?string $stderr = null,
        ?string $errorLog = null,
    ): Settled {
        return new Settled(
            new Job($kind, $name, null, null),
            $status,
            $body,
            $headers,
            $error,
            $exitCode,
            $stdout,
            $stderr,
            $errorLog,
            null,
            null,
        );
    }

    /**
     * @return array<string, int>
     */
    private function pollsByName(FakeIo $io): array
    {
        $polls = [];
        foreach ($io->starts as $start) {
            $polls[$start['job']->name] = $start['polls'];
        }

        return $polls;
    }

    /**
     * @return array<string, Job>
     */
    private function jobsByName(FakeIo $io): array
    {
        $jobs = [];
        foreach ($io->starts as $start) {
            $jobs[$start['job']->name] = $start['job'];
        }

        return $jobs;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            self::fail('Step file is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
