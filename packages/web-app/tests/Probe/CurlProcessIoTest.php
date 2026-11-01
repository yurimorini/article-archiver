<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Probe;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\Sqlite\SqliteSchema;
use Yumo\Eleanor\Probe\Catalog;
use Yumo\Eleanor\Probe\CurlProcessIo;
use Yumo\Eleanor\Probe\Job;
use Yumo\Eleanor\Probe\LogTail;
use Yumo\Eleanor\Probe\Preset;
use Yumo\Eleanor\Probe\Record;
use Yumo\Eleanor\Probe\Scheduler;
use Yumo\Eleanor\Probe\Settled;

/**
 * Proves curl and process I/O settles probe jobs, and the scheduler attaches the request log.
 */
final class CurlProcessIoTest extends TestCase
{
    private const string TOKEN = 'probe-secret-token';

    private const string EMAIL = 'owner@example.com';

    private const string MIXED_EMAIL = 'Owner@Example.com';

    private string $root;

    private ?CurlProcessIo $io = null;

    private mixed $takenPort = null;

    private ?int $childPid = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/eleanor-probe-io-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        if ($this->io instanceof CurlProcessIo) {
            $this->io->stop();
            $this->io = null;
        }

        if (is_resource($this->takenPort)) {
            fclose($this->takenPort);
            $this->takenPort = null;
        }

        if ($this->childPid !== null && $this->running($this->childPid)) {
            exec('kill ' . $this->childPid);
            $this->childPid = null;
        }

        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function test_database_path_is_the_sha256_prefix_without_lowercasing_email(): void
    {
        self::assertSame(
            '/data/c8cd3c6427301eaf.sqlite',
            CurlProcessIo::databasePath('/data', self::EMAIL),
        );
        self::assertSame(
            '/data/66e79b6a193b2290.sqlite',
            CurlProcessIo::databasePath('/data', self::MIXED_EMAIL),
        );
    }

    public function test_sql_poll_returns_body_bytes_for_the_inserted_url(): void
    {
        $package = $this->root . '/package';
        $dataDir = $package . '/var/data';
        mkdir($dataDir, 0o777, true);
        $absolute = $dataDir . '/66e79b6a193b2290.sqlite';
        $pdo = new \PDO('sqlite:' . $absolute);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        new SqliteSchema()->migrate($pdo);
        $pdo->exec(
            "INSERT INTO archive (url, title, excerpt, site_name, created_at) VALUES ('https://example.com/a', 'A', NULL, 'Example', '2026-11-01T15:41:02Z')",
        );
        $insert = $pdo->prepare('INSERT INTO archive_body (archive_id, html) VALUES (:id, :html)');
        $insert->execute(['id' => (int) $pdo->lastInsertId(), 'html' => 'hello']);
        unset($insert, $pdo);
        chmod($absolute, 0o444);

        $io = $this->makeIo($package, $this->root . '/run', $this->freePort(), self::MIXED_EMAIL);
        mkdir($this->root . '/run');
        $io->start(new Job('sql', 'sql', null, 'https://example.com/a'));
        $settled = $io->poll();

        self::assertCount(1, $settled);
        self::assertNull($settled[0]->error);
        self::assertSame('var/data/66e79b6a193b2290.sqlite', $settled[0]->sqlFile);
        self::assertIsArray($settled[0]->rows);
        self::assertCount(1, $settled[0]->rows);
        self::assertSame(1, $settled[0]->rows[0]['id']);
        self::assertSame('https://example.com/a', $settled[0]->rows[0]['url']);
        self::assertSame('A', $settled[0]->rows[0]['title']);
        self::assertNull($settled[0]->rows[0]['excerpt']);
        self::assertSame('Example', $settled[0]->rows[0]['site_name']);
        self::assertSame('2026-11-01T15:41:02Z', $settled[0]->rows[0]['created_at']);
        self::assertSame(5, $settled[0]->rows[0]['body_bytes']);
        $this->assertNoToken($settled[0]);
    }

    public function test_sql_poll_returns_no_rows_when_the_file_is_missing_and_does_not_create_it(): void
    {
        $package = $this->root . '/package';
        $dataDir = $package . '/var/data';
        mkdir($dataDir, 0o777, true);
        $absolute = $dataDir . '/c8cd3c6427301eaf.sqlite';

        $io = $this->makeIo($package, $this->root . '/run', $this->freePort());
        mkdir($this->root . '/run');
        $io->start(new Job('sql', 'sql', null, 'https://example.com/missing'));
        $settled = $io->poll();

        self::assertCount(1, $settled);
        self::assertSame([], $settled[0]->rows);
        self::assertNull($settled[0]->error);
        self::assertSame('var/data/c8cd3c6427301eaf.sqlite', $settled[0]->sqlFile);
        self::assertFileDoesNotExist($absolute);
    }

    public function test_sql_poll_returns_no_rows_when_the_url_is_missing(): void
    {
        $package = $this->root . '/package';
        $dataDir = $package . '/var/data';
        mkdir($dataDir, 0o777, true);
        $absolute = $dataDir . '/c8cd3c6427301eaf.sqlite';
        $pdo = new \PDO('sqlite:' . $absolute);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        new SqliteSchema()->migrate($pdo);
        $pdo->exec("INSERT INTO archive (url, title, created_at) VALUES ('https://example.com/other', 'Other', '2026-11-01T15:41:02Z')");
        $insert = $pdo->prepare('INSERT INTO archive_body (archive_id, html) VALUES (:id, :html)');
        $insert->execute(['id' => (int) $pdo->lastInsertId(), 'html' => 'hello']);
        unset($insert, $pdo);

        $io = $this->makeIo($package, $this->root . '/run', $this->freePort());
        mkdir($this->root . '/run');
        $io->start(new Job('sql', 'sql', null, 'https://example.com/a'));
        $settled = $io->poll();

        self::assertCount(1, $settled);
        self::assertSame([], $settled[0]->rows);
        self::assertNull($settled[0]->error);
    }

    public function test_taken_port_settles_server_with_an_error_without_waiting_out_boot(): void
    {
        $socket = stream_socket_server('tcp://0.0.0.0:8765', $errno, $error);
        if ($socket === false) {
            self::fail('Could not bind 0.0.0.0:8765: ' . $error);
        }
        $this->takenPort = $socket;

        $package = $this->root . '/package';
        mkdir($package);
        $io = $this->makeIo($package, $this->root . '/run', 8765);
        $started = microtime(true);
        $io->start(new Job('server', 'server', null, null));
        $settled = $this->awaitSettled($io, 'server', 30);

        self::assertLessThan(3.0, microtime(true) - $started);
        self::assertIsString($settled->error);
        $this->assertNoToken($settled);
    }

    public function test_http_jobs_send_method_body_and_authorization_and_keep_selected_headers(): void
    {
        $package = $this->writeRouter($this->root . '/package');
        $port = $this->freePort();
        $io = $this->makeIo($package, $this->root . '/run', $port);
        $this->boot($io);

        $io->start(new Job('http', 'post', new Preset('POST', '/api/archives', 'application/json', '{"url":"https://example.com/"}', 'bearer'), null));
        $io->start(new Job('http', 'wrong-bearer', Catalog::preset('wrong-bearer'), null));
        $io->start(new Job('http', 'no-bearer', Catalog::preset('no-bearer'), null));
        $io->start(new Job('http', 'list', Catalog::preset('list'), null));
        $io->start(new Job('http', 'not-found', Catalog::preset('not-found'), null));

        $byName = $this->awaitAll($io, ['post', 'wrong-bearer', 'no-bearer', 'list', 'not-found']);

        $post = $this->payload($byName['post']);
        self::assertSame(200, $byName['post']->status);
        self::assertNull($byName['post']->error);
        self::assertSame('POST', $post['method']);
        self::assertSame('/api/archives', $post['path']);
        self::assertSame('bearer', $post['authorization']);
        self::assertSame('{"url":"https://example.com/"}', $post['body']);
        self::assertStringContainsString('application/json', $post['content_type']);
        $this->assertSelectedHeaders($byName['post']->headers);

        $wrong = $this->payload($byName['wrong-bearer']);
        self::assertNull($byName['wrong-bearer']->error);
        self::assertSame('wrong', $wrong['authorization']);
        self::assertSame('{"url":"https://example.com/"}', $wrong['body']);

        $absent = $this->payload($byName['no-bearer']);
        self::assertNull($byName['no-bearer']->error);
        self::assertSame('absent', $absent['authorization']);

        $list = $this->payload($byName['list']);
        self::assertNull($byName['list']->error);
        self::assertSame('GET', $list['method']);
        self::assertSame('/api/archives', $list['path']);
        self::assertSame('bearer', $list['authorization']);
        self::assertSame('', $list['content_type']);
        self::assertSame('', $list['body']);

        self::assertSame(404, $byName['not-found']->status);
        self::assertNull($byName['not-found']->error);
        $this->assertNoToken($byName['post']);
    }

    public function test_poll_does_not_block_while_an_http_transfer_is_open(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            self::fail('Could not listen: ' . $error);
        }
        $this->takenPort = $socket;
        $name = stream_socket_get_name($socket, false);
        self::assertIsString($name);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $io = $this->makeIo($this->root . '/package', $this->root . '/run', $port);
        mkdir($this->root . '/package');
        $io->start(new Job('http', 'list', Catalog::preset('list'), null));
        $started = microtime(true);
        self::assertSame([], $io->poll());
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function test_curl_error_settles_with_an_error_and_a_null_response(): void
    {
        $port = $this->freePort();
        $io = $this->makeIo($this->root . '/package', $this->root . '/run', $port);
        mkdir($this->root . '/package');
        $started = microtime(true);
        $io->start(new Job('http', 'list', Catalog::preset('list'), null));
        $settled = $this->awaitSettled($io, 'list', 20);

        self::assertLessThan(3.0, microtime(true) - $started);
        self::assertIsString($settled->error);
        self::assertNull($settled->status);
        self::assertNull($settled->body);
        self::assertSame([], $settled->headers);
        $this->assertNoToken($settled);
    }

    public function test_log_lines_are_the_raw_appended_tail_and_the_read_is_not_destructive(): void
    {
        $package = $this->writeRouter($this->root . '/package');
        $logDir = $package . '/var/log';
        mkdir($logDir, 0o777, true);
        $log = $logDir . '/eleanor.dev.log';
        file_put_contents($log, "before\n");

        $io = $this->makeIo($package, $this->root . '/run', $this->freePort());
        self::assertSame([], $io->logLines());

        $this->boot($io);
        file_put_contents($log, "after\n", FILE_APPEND);
        file_put_contents($log, 'partial', FILE_APPEND);

        $lines = $io->logLines();
        self::assertSame(['after', 'partial'], $lines);
        self::assertSame($lines, $io->logLines());
        self::assertSame("before\nafter\npartial", (string) file_get_contents($log));
    }

    public function test_missing_log_file_returns_no_lines(): void
    {
        $package = $this->root . '/package';
        mkdir($package);
        $io = $this->makeIo($package, $this->root . '/run', $this->freePort());

        self::assertSame([], $io->logLines());
    }

    public function test_import_runs_console_in_the_run_directory_and_copies_error_log(): void
    {
        $package = $this->root . '/package';
        $run = $this->root . '/run';
        mkdir($run);
        file_put_contents($run . '/sample.txt', "https://example.com/a\n");
        $this->writeConsole($package, <<<'PHP'
            <?php
            file_put_contents('observed.json', json_encode([
                'cwd' => getcwd(),
                'argv' => $_SERVER['argv'],
            ], JSON_THROW_ON_ERROR));
            fwrite(STDOUT, "Written 1\n");
            fwrite(STDERR, "warn\n");
            file_put_contents('error.log', "row\n");
            PHP);

        $io = $this->makeIo($package, $run, $this->freePort());
        $io->start(new Job('import', 'import', null, null));
        $settled = $this->awaitSettled($io, 'import', 40);

        $observed = json_decode((string) file_get_contents($run . '/observed.json'), true);
        self::assertIsArray($observed);
        self::assertSame(realpath($run), $observed['cwd']);
        self::assertSame([
            $package . '/bin/console',
            'archive:import',
            $run . '/sample.txt',
            '--owner=' . self::EMAIL,
        ], $observed['argv']);
        self::assertNull($settled->error);
        self::assertSame(0, $settled->exitCode);
        self::assertSame("Written 1\n", $settled->stdout);
        self::assertSame("warn\n", $settled->stderr);
        self::assertSame("row\n", $settled->errorLog);
        $this->assertNoToken($settled);
    }

    public function test_import_without_a_written_summary_settles_with_an_error(): void
    {
        $package = $this->root . '/package';
        $run = $this->root . '/run';
        mkdir($run);
        $this->writeConsole($package, <<<'PHP'
            <?php
            fwrite(STDOUT, "nope\n");
            exit(2);
            PHP);

        $io = $this->makeIo($package, $run, $this->freePort());
        $io->start(new Job('import', 'import', null, null));
        $settled = $this->awaitSettled($io, 'import', 40);

        self::assertSame(2, $settled->exitCode);
        self::assertSame("nope\n", $settled->stdout);
        self::assertNull($settled->errorLog);
        self::assertIsString($settled->error);
        self::assertMatchesRegularExpression('/^[^.].+\.$/', $settled->error);
    }

    public function test_a_failed_http_step_does_not_kill_a_running_import(): void
    {
        $package = $this->root . '/package';
        $run = $this->root . '/run';
        mkdir($run);
        $this->writeConsole($package, <<<'PHP'
            <?php
            file_put_contents('pid', (string) getmypid());
            sleep(30);
            echo "Written 1\n";
            PHP);

        $port = $this->freePort();
        $io = $this->makeIo($package, $run, $port);
        $io->start(new Job('import', 'import', null, null));
        $io->start(new Job('http', 'list', Catalog::preset('list'), null));

        $sawHttp = false;
        $started = microtime(true);
        for ($attempt = 0; $attempt < 40; ++$attempt) {
            foreach ($io->poll() as $settled) {
                self::assertNotSame('import', $settled->job->name);
                if ($settled->job->name === 'list') {
                    $sawHttp = true;
                    self::assertIsString($settled->error);
                }
            }

            if ($sawHttp && is_file($run . '/pid')) {
                break;
            }

            usleep(50000);
        }

        self::assertLessThan(3.0, microtime(true) - $started);
        self::assertTrue($sawHttp);
        $pid = (int) file_get_contents($run . '/pid');
        $this->childPid = $pid;
        self::assertTrue($this->running($pid));

        $io->stop();
        $this->io = null;
        self::assertFalse($this->running($pid));
        $this->childPid = null;
    }

    public function test_scheduler_fills_log_from_the_request_id(): void
    {
        $run = $this->root . '/run';
        mkdir($run);
        $io = new FakeIo();
        $io->script('server', $this->outcome(new Job('server', 'server', null, null)));
        $io->script('list', $this->outcome(
            new Job('http', 'list', null, null),
            status: 200,
            body: '[]',
            headers: ['content-type' => 'application/json', 'x-request-id' => 'req-1'],
        ));
        $match = '{"message":"http.request.finish","extra":{"request_id":"req-1"}}';
        $other = '{"message":"http.request.finish","extra":{"request_id":"req-2"}}';
        $scheduler = new Scheduler(
            $io,
            new Record($run),
            'http://127.0.0.1:8000',
            'https://example.com/a',
            static fn (): array => [$match, 'not-json', $other],
        );

        self::assertSame(0, $scheduler->run(['list']));
        $step = $this->readJson($run . '/steps/01-list.json');
        self::assertIsArray($step['log']);
        self::assertCount(1, $step['log']);
        self::assertIsArray($step['log'][0]);
        self::assertSame('req-1', $step['log'][0]['extra']['request_id']);
    }

    public function test_scheduler_leaves_log_empty_without_a_request_id(): void
    {
        $run = $this->root . '/run';
        mkdir($run);
        $io = new FakeIo();
        $io->script('server', $this->outcome(new Job('server', 'server', null, null)));
        $io->script('archive', $this->outcome(
            new Job('http', 'archive', null, null),
            status: 201,
            body: '{"id":1}',
            headers: ['content-type' => 'application/json'],
        ));
        $scheduler = new Scheduler(
            $io,
            new Record($run),
            'http://127.0.0.1:8000',
            'https://example.com/a',
            static fn (): array => ['{"extra":{"request_id":"req-1"}}'],
        );

        self::assertSame(0, $scheduler->run(['archive']));
        $step = $this->readJson($run . '/steps/01-archive.json');
        self::assertSame([], $step['log']);
    }

    private function makeIo(string $packageDir, string $runDir, int $port, string $email = self::EMAIL): CurlProcessIo
    {
        $io = new CurlProcessIo($packageDir, $runDir, $email, $port, self::TOKEN, new LogTail());
        $this->io = $io;

        return $io;
    }

    private function boot(CurlProcessIo $io): void
    {
        $io->start(new Job('server', 'server', null, null));
        $settled = $this->awaitSettled($io, 'server', 80);
        self::assertNull($settled->error);
    }

    private function awaitSettled(CurlProcessIo $io, string $name, int $maxPolls): Settled
    {
        for ($attempt = 0; $attempt < $maxPolls; ++$attempt) {
            foreach ($io->poll() as $settled) {
                if ($settled->job->name === $name) {
                    return $settled;
                }
            }

            usleep(50000);
        }

        self::fail('The ' . $name . ' job did not settle.');
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, Settled>
     */
    private function awaitAll(CurlProcessIo $io, array $names): array
    {
        $pending = array_fill_keys($names, true);
        $found = [];
        for ($attempt = 0; $attempt < 80 && $pending !== []; ++$attempt) {
            foreach ($io->poll() as $settled) {
                $found[$settled->job->name] = $settled;
                unset($pending[$settled->job->name]);
            }

            if ($pending !== []) {
                usleep(50000);
            }
        }

        self::assertSame([], array_keys($pending));

        return $found;
    }

    private function writeRouter(string $packageDir): string
    {
        mkdir($packageDir . '/public', 0o777, true);
        file_put_contents($packageDir . '/router.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            $path = $_SERVER['REQUEST_URI'] ?? '/';
            if (str_starts_with($path, '/api/no-such-route')) {
                http_response_code(404);
            }

            header('Content-Type: text/plain');
            header('X-Request-Id: req-probe');
            header('Allow: PUT');
            header('X-Ignored: no');

            $authorization = '';
            foreach (getallheaders() as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    $authorization = $value;
                }
            }

            if ($authorization === '') {
                $label = 'absent';
            } elseif ($authorization === 'Bearer wrong') {
                $label = 'wrong';
            } elseif (str_starts_with($authorization, 'Bearer ')) {
                $label = 'bearer';
            } else {
                $label = 'other';
            }

            echo json_encode([
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'path' => $path,
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
                'body' => file_get_contents('php://input'),
                'authorization' => $label,
            ], JSON_THROW_ON_ERROR);
            PHP);

        return $packageDir;
    }

    private function writeConsole(string $packageDir, string $source): void
    {
        mkdir($packageDir . '/bin', 0o777, true);
        file_put_contents($packageDir . '/bin/console', $source);
    }

    /**
     * @return array{method: string, path: string, content_type: string, body: string, authorization: string}
     */
    private function payload(Settled $settled): array
    {
        self::assertIsString($settled->body);
        $decoded = json_decode($settled->body, true);
        self::assertIsArray($decoded);

        return [
            'method' => is_string($decoded['method'] ?? null) ? $decoded['method'] : '',
            'path' => is_string($decoded['path'] ?? null) ? $decoded['path'] : '',
            'content_type' => is_string($decoded['content_type'] ?? null) ? $decoded['content_type'] : '',
            'body' => is_string($decoded['body'] ?? null) ? $decoded['body'] : '',
            'authorization' => is_string($decoded['authorization'] ?? null) ? $decoded['authorization'] : '',
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private function assertSelectedHeaders(array $headers): void
    {
        self::assertEqualsCanonicalizing(['allow', 'content-type', 'x-request-id'], array_keys($headers));
        self::assertSame('req-probe', $headers['x-request-id']);
        self::assertSame('PUT', $headers['allow']);
        self::assertStringStartsWith('text/plain', $headers['content-type']);
        foreach (array_keys($headers) as $name) {
            self::assertSame(strtolower($name), $name);
        }
    }

    private function assertNoToken(Settled $settled): void
    {
        $encoded = json_encode([
            $settled->error,
            $settled->stdout,
            $settled->stderr,
            $settled->errorLog,
            $settled->sqlFile,
            $settled->headers,
        ]);
        self::assertIsString($encoded);
        self::assertStringNotContainsString(self::TOKEN, $encoded);
    }

    private function running(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if (!is_string($stat)) {
            return false;
        }

        $close = strrpos($stat, ')');
        if ($close === false) {
            return false;
        }

        $state = substr($stat, $close + 2, 1);

        return $state !== 'Z' && $state !== '';
    }

    /**
     * @param array<string, string> $headers
     */
    private function outcome(
        Job $job,
        ?int $status = null,
        ?string $body = null,
        array $headers = [],
    ): Settled {
        return new Settled($job, $status, $body, $headers, null, null, null, null, null, null, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            self::fail('Could not allocate a port.');
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($name);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        self::assertGreaterThan(0, $port);

        return $port;
    }
}
