<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

/**
 * Runs probe jobs with curl_multi and proc_open.
 *
 * `poll` never waits on a transfer or a child. The server boot limit is 10 seconds,
 * each HTTP transfer limit is 60 seconds, and the import child limit is 600 seconds.
 */
final class CurlProcessIo implements Io
{
    private const int HTTP_TIMEOUT_SECONDS = 60;

    private const float SERVER_BOOT_SECONDS = 10.0;

    private const float IMPORT_TIMEOUT_SECONDS = 600.0;

    private const string SQL = <<<'SQL'
        SELECT archive.id AS id,
               archive.url AS url,
               archive.title AS title,
               archive.excerpt AS excerpt,
               archive.site_name AS site_name,
               archive.created_at AS created_at,
               length(archive_body.html) AS body_bytes
        FROM archive
        INNER JOIN archive_body ON archive_body.archive_id = archive.id
        WHERE archive.url = :url
        SQL;

    private readonly \CurlMultiHandle $multi;

    /**
     * Kept for the probe runner. The scheduler filters lines from `logLines()`.
     *
     * @phpstan-ignore property.onlyWritten
     */
    private readonly LogTail $log;

    /** @var list<Settled> */
    private array $ready = [];

    /** @var array<int, array{job: Job, handle: \CurlHandle, headers: string}> */
    private array $transfers = [];

    /** @var list<Job> */
    private array $sqlJobs = [];

    private ?\CurlHandle $boot = null;

    private ?bool $bootOk = null;

    private ?Job $serverJob = null;

    /** @var resource|null */
    private $serverProcess = null;

    /** @var resource|null */
    private $serverStdout = null;

    /** @var resource|null */
    private $serverStderr = null;

    private string $serverStdoutText = '';

    private string $serverStderrText = '';

    private float $serverStartedAt = 0.0;

    private bool $serverSettled = false;

    private ?Job $importJob = null;

    /** @var resource|null */
    private $importProcess = null;

    /** @var resource|null */
    private $importStdout = null;

    /** @var resource|null */
    private $importStderr = null;

    private string $importStdoutText = '';

    private string $importStderrText = '';

    private float $importStartedAt = 0.0;

    private ?int $logOffset = null;

    private bool $stopped = false;

    /**
     * Stores the package directory, the run directory, the owner, the port, the bearer token, and the log tail.
     */
    public function __construct(
        private readonly string $packageDir,
        private readonly string $runDir,
        private readonly string $ownerEmail,
        private readonly int $port,
        private readonly string $token,
        LogTail $log,
    ) {
        $this->multi = curl_multi_init();
        $this->log = $log;
    }

    /**
     * Returns the owner database path. The email is hashed as given.
     */
    public static function databasePath(string $dataDir, string $email): string
    {
        return $dataDir . '/' . substr(hash('sha256', $email), 0, 16) . '.sqlite';
    }

    /**
     * Starts `$job`. It stays in flight until `poll` returns it.
     */
    public function start(Job $job): void
    {
        if ($this->stopped) {
            return;
        }

        if ($job->kind === 'server') {
            $this->startServer($job);

            return;
        }

        if ($job->kind === 'import') {
            $this->startImport($job);

            return;
        }

        if ($job->kind === 'http') {
            $this->startHttp($job);

            return;
        }

        if ($job->kind === 'sql') {
            $this->sqlJobs[] = $job;

            return;
        }

        $this->ready[] = $this->settled($job, 'The probe job could not be started.');
    }

    /**
     * Returns the jobs that have settled since the previous poll.
     *
     * @return list<Settled>
     */
    public function poll(): array
    {
        if ($this->stopped) {
            return [];
        }

        $this->collectFinished();
        $this->pumpImport();
        $this->pumpServer();
        $this->pumpSql();

        $settled = $this->ready;
        $this->ready = [];

        return $settled;
    }

    /**
     * Stops the server, and stops the import child when it is still running.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        $this->dropBoot();
        foreach ($this->transfers as $transfer) {
            curl_multi_remove_handle($this->multi, $transfer['handle']);
        }
        $this->transfers = [];
        curl_multi_close($this->multi);

        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            $this->reap($this->serverProcess, $this->serverStdout, $this->serverStderr);
            $this->serverProcess = null;
            $this->serverStdout = null;
            $this->serverStderr = null;
        }

        if (is_resource($this->importProcess)) {
            $importProcess = $this->importProcess;
            if (proc_get_status($importProcess)['running'] === true) {
                proc_terminate($importProcess);
            }
            $this->reap($importProcess, $this->importStdout, $this->importStderr);
            $this->importProcess = null;
            $this->importStdout = null;
            $this->importStderr = null;
            $this->importJob = null;
        }
    }

    /**
     * Returns the raw log lines appended since the server job settled.
     *
     * A missing file returns an empty list. The file is left in place.
     *
     * @return list<string>
     *
     * @phpstan-impure
     */
    public function logLines(): array
    {
        $path = $this->packageDir . '/var/log/eleanor.dev.log';
        if (!is_file($path) || $this->logOffset === null) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        if ($this->logOffset > 0) {
            fseek($handle, $this->logOffset);
        }

        $chunk = stream_get_contents($handle);
        fclose($handle);
        if (!is_string($chunk) || $chunk === '') {
            return [];
        }

        $lines = explode("\n", $chunk);
        $last = array_key_last($lines);
        if ($last !== null && $lines[$last] === '') {
            array_pop($lines);
        }

        return $lines;
    }

    public function __destruct()
    {
        $this->stop();
    }

    private function startServer(Job $job): void
    {
        $opened = $this->openProcess(
            ['php', '-S', '0.0.0.0:' . $this->port, '-t', 'public', 'router.php'],
            $this->packageDir,
        );
        if ($opened === null) {
            $this->captureLogOffset();
            $this->ready[] = $this->settled($job, 'The server process could not be started.');

            return;
        }

        $this->serverJob = $job;
        $this->serverProcess = $opened['process'];
        $this->serverStdout = $opened['stdout'];
        $this->serverStderr = $opened['stderr'];
        $this->serverStdoutText = '';
        $this->serverStderrText = '';
        $this->serverStartedAt = microtime(true);
        $this->serverSettled = false;
        $this->armBoot();
    }

    private function startImport(Job $job): void
    {
        $opened = $this->openProcess(
            [
                'php',
                $this->packageDir . '/bin/console',
                'archive:import',
                $this->runDir . '/sample.txt',
                '--owner=' . $this->ownerEmail,
            ],
            $this->runDir,
        );
        if ($opened === null) {
            $this->ready[] = $this->settled($job, 'The import process could not be started.');

            return;
        }

        $this->importJob = $job;
        $this->importProcess = $opened['process'];
        $this->importStdout = $opened['stdout'];
        $this->importStderr = $opened['stderr'];
        $this->importStdoutText = '';
        $this->importStderrText = '';
        $this->importStartedAt = microtime(true);
    }

    private function startHttp(Job $job): void
    {
        $preset = $job->preset;
        if (!$preset instanceof Preset) {
            $this->ready[] = $this->settled($job, 'The HTTP request could not be sent.');

            return;
        }

        $handle = curl_init('http://127.0.0.1:' . $this->port . $preset->path);
        if ($handle === false) {
            $this->ready[] = $this->settled($job, 'The HTTP transfer failed.');

            return;
        }

        $this->configureHandle($handle, $preset, $job->url);
        curl_multi_add_handle($this->multi, $handle);
        $this->transfers[spl_object_id($handle)] = [
            'job' => $job,
            'handle' => $handle,
            'headers' => '',
        ];
    }

    private function armBoot(): void
    {
        if ($this->boot instanceof \CurlHandle) {
            return;
        }

        $handle = curl_init('http://127.0.0.1:' . $this->port . '/');
        if ($handle === false) {
            return;
        }

        $this->configureHandle($handle, null);
        curl_multi_add_handle($this->multi, $handle);
        $this->boot = $handle;
        $this->bootOk = null;
    }

    private function configureHandle(\CurlHandle $handle, ?Preset $preset, ?string $url = null): void
    {
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, self::HTTP_TIMEOUT_SECONDS);
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function (\CurlHandle $curl, string $line): int {
            $id = spl_object_id($curl);
            if (isset($this->transfers[$id])) {
                $this->transfers[$id]['headers'] .= $line;
            }

            return strlen($line);
        });

        if (!$preset instanceof Preset) {
            return;
        }

        $method = $preset->method;
        if ($method !== '') {
            curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        }
        $headers = $this->requestHeaders($preset);
        if ($headers !== []) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        }
        $body = $preset->body;
        if ($body === null && is_string($url) && $url !== '') {
            $body = json_encode(['url' => $url], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
    }

    /**
     * @return list<string>
     */
    private function requestHeaders(Preset $preset): array
    {
        $headers = [];
        if ($preset->contentType !== null) {
            $headers[] = 'Content-Type: ' . $preset->contentType;
        }

        if ($preset->authorization === 'bearer') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        } elseif ($preset->authorization === 'wrong') {
            $headers[] = 'Authorization: Bearer wrong';
        }

        return $headers;
    }

    private function collectFinished(): void
    {
        $running = 0;
        curl_multi_exec($this->multi, $running);

        while (true) {
            $info = curl_multi_info_read($this->multi);
            if ($info === false) {
                break;
            }

            $handle = $info['handle'] ?? null;
            if (!$handle instanceof \CurlHandle || ($info['msg'] ?? null) !== CURLMSG_DONE) {
                continue;
            }

            $this->finishHandle($handle);
        }
    }

    private function finishHandle(\CurlHandle $handle): void
    {
        if ($this->boot instanceof \CurlHandle && $this->boot === $handle) {
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $this->bootOk = curl_errno($handle) === 0 && is_int($status) && $status > 0;
            $this->dropBoot();

            return;
        }

        $id = spl_object_id($handle);
        if (!isset($this->transfers[$id])) {
            curl_multi_remove_handle($this->multi, $handle);

            return;
        }

        $transfer = $this->transfers[$id];
        unset($this->transfers[$id]);
        curl_multi_remove_handle($this->multi, $handle);
        $this->ready[] = $this->httpSettled($transfer['job'], $handle, $transfer['headers']);
    }

    private function httpSettled(Job $job, \CurlHandle $handle, string $rawHeaders): Settled
    {
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (curl_errno($handle) !== 0 || !is_int($status) || $status === 0) {
            return $this->settled($job, 'The HTTP transfer failed.');
        }

        $body = curl_multi_getcontent($handle);

        return $this->settled(
            $job,
            null,
            $status,
            is_string($body) ? $body : '',
            $this->responseHeaders($rawHeaders),
        );
    }

    /**
     * @return array<string, string>
     */
    private function responseHeaders(string $raw): array
    {
        $kept = [];
        $lines = preg_split("/\r\n|\n|\r/", $raw);
        if (!is_array($lines)) {
            return [];
        }

        foreach ($lines as $line) {
            if (!is_string($line) || !str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            if ($name !== 'content-type' && $name !== 'x-request-id' && $name !== 'allow') {
                continue;
            }

            $kept[$name] = trim($value);
        }

        return $kept;
    }

    private function pumpImport(): void
    {
        $this->readImportPipes();
        $job = $this->importJob;
        $process = $this->importProcess;
        if (!$job instanceof Job || !is_resource($process)) {
            return;
        }

        $status = proc_get_status($process);
        $timedOut = (microtime(true) - $this->importStartedAt) >= self::IMPORT_TIMEOUT_SECONDS;
        if ($status['running'] === true && !$timedOut) {
            return;
        }

        if ($status['running'] === true) {
            proc_terminate($process);
            $status = proc_get_status($process);
        }

        $this->readImportPipes();
        $exitCode = $status['running'] !== true && $status['exitcode'] !== -1 ? $status['exitcode'] : null;
        $stdout = $this->importStdoutText;
        $stderr = $this->importStderrText;
        $this->reap($process, $this->importStdout, $this->importStderr);
        $this->importProcess = null;
        $this->importStdout = null;
        $this->importStderr = null;
        $this->importJob = null;

        $error = $timedOut
            ? 'The import process did not finish.'
            : ImportResult::error($exitCode, $stdout);

        $this->ready[] = $this->settled(
            $job,
            $error,
            exitCode: $exitCode,
            stdout: $stdout,
            stderr: $stderr,
            errorLog: $this->readErrorLog(),
        );
    }

    private function readImportPipes(): void
    {
        if (is_resource($this->importStdout)) {
            $this->importStdoutText .= $this->readAvailable($this->importStdout);
        }

        if (is_resource($this->importStderr)) {
            $this->importStderrText .= $this->readAvailable($this->importStderr);
        }
    }

    private function readErrorLog(): ?string
    {
        $path = $this->runDir . '/error.log';
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }

    private function pumpServer(): void
    {
        $this->drainServer();
        if (!$this->serverJob instanceof Job || $this->serverSettled) {
            return;
        }

        if ($this->addressInUse()) {
            $this->settleServer('The server address is already in use.');

            return;
        }

        if ($this->serverExited()) {
            $this->settleServer('The server process exited before it answered.');

            return;
        }

        if ($this->bootOk === true) {
            $this->settleServer(null);

            return;
        }

        if ((microtime(true) - $this->serverStartedAt) >= self::SERVER_BOOT_SECONDS) {
            $this->settleServer('The server did not answer.');

            return;
        }

        if (!$this->boot instanceof \CurlHandle) {
            $this->armBoot();
        }
    }

    private function drainServer(): void
    {
        if (!is_resource($this->serverStdout) && !is_resource($this->serverStderr)) {
            return;
        }

        $stdout = is_resource($this->serverStdout) ? $this->readAvailable($this->serverStdout) : '';
        $stderr = is_resource($this->serverStderr) ? $this->readAvailable($this->serverStderr) : '';
        if ($this->serverSettled) {
            return;
        }

        $this->serverStdoutText .= $stdout;
        $this->serverStderrText .= $stderr;
    }

    private function addressInUse(): bool
    {
        return str_contains($this->serverStderrText, 'Address already in use')
            || str_contains($this->serverStdoutText, 'Address already in use');
    }

    private function serverExited(): bool
    {
        if (!is_resource($this->serverProcess)) {
            return true;
        }

        return proc_get_status($this->serverProcess)['running'] !== true;
    }

    private function settleServer(?string $error): void
    {
        $job = $this->serverJob;
        if (!$job instanceof Job || $this->serverSettled) {
            return;
        }

        $this->serverSettled = true;
        $this->serverJob = null;
        $this->captureLogOffset();
        $this->dropBoot();
        if ($error !== null) {
            if (is_resource($this->serverProcess)) {
                $process = $this->serverProcess;
                if (proc_get_status($process)['running'] === true) {
                    proc_terminate($process);
                }
                $this->reap($process, $this->serverStdout, $this->serverStderr);
            }
            $this->serverProcess = null;
            $this->serverStdout = null;
            $this->serverStderr = null;
        }

        $this->ready[] = $this->settled($job, $error);
    }

    private function captureLogOffset(): void
    {
        if ($this->logOffset !== null) {
            return;
        }

        $path = $this->packageDir . '/var/log/eleanor.dev.log';
        if (!is_file($path)) {
            $this->logOffset = 0;

            return;
        }

        $size = filesize($path);
        $this->logOffset = is_int($size) ? $size : 0;
    }

    private function pumpSql(): void
    {
        $jobs = $this->sqlJobs;
        $this->sqlJobs = [];
        foreach ($jobs as $job) {
            $this->ready[] = $this->querySql($job);
        }
    }

    private function querySql(Job $job): Settled
    {
        $absolute = self::databasePath($this->packageDir . '/var/data', $this->ownerEmail);
        $relative = 'var/data/' . basename($absolute);
        if (!is_string($job->url) || !is_file($absolute)) {
            return $this->settled($job, null, rows: [], sqlFile: $relative);
        }

        try {
            $pdo = new \PDO('sqlite:' . $absolute, options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READWRITE,
            ]);
            $pdo->exec('PRAGMA query_only = ON');

            return $this->settled($job, null, rows: $this->selectRows($pdo, $job->url), sqlFile: $relative);
        } catch (\PDOException) {
            return $this->settled($job, 'The archive database could not be read.', rows: [], sqlFile: $relative);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectRows(\PDO $pdo, string $url): array
    {
        $statement = $pdo->prepare(self::SQL);
        if (!$statement instanceof \PDOStatement) {
            throw new \PDOException('The archive query could not be prepared.');
        }

        $statement->execute(['url' => $url]);
        $rows = [];
        while (true) {
            $row = $statement->fetch();
            if ($row === false) {
                break;
            }

            if (!is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'url' => is_string($row['url'] ?? null) ? $row['url'] : '',
                'title' => is_string($row['title'] ?? null) ? $row['title'] : '',
                'excerpt' => is_string($row['excerpt'] ?? null) ? $row['excerpt'] : null,
                'site_name' => is_string($row['site_name'] ?? null) ? $row['site_name'] : null,
                'created_at' => is_string($row['created_at'] ?? null) ? $row['created_at'] : '',
                'body_bytes' => (int) ($row['body_bytes'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $command
     *
     * @return array{process: resource, stdout: resource, stderr: resource}|null
     */
    private function openProcess(array $command, string $cwd): ?array
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd);
        if (!is_resource($process)) {
            return null;
        }

        $stdin = $pipes[0] ?? null;
        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;
        if (is_resource($stdin)) {
            fclose($stdin);
        }

        if (!is_resource($stdout) || !is_resource($stderr) || !stream_set_blocking($stdout, false) || !stream_set_blocking($stderr, false)) {
            if (is_resource($stdout)) {
                fclose($stdout);
            }
            if (is_resource($stderr)) {
                fclose($stderr);
            }
            proc_close($process);

            return null;
        }

        return [
            'process' => $process,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * @param resource $pipe
     */
    private function readAvailable($pipe): string
    {
        $chunk = stream_get_contents($pipe);

        return is_string($chunk) ? $chunk : '';
    }

    /**
     * @param resource $process
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    private function reap($process, $stdout, $stderr): void
    {
        if (is_resource($stdout)) {
            fclose($stdout);
        }

        if (is_resource($stderr)) {
            fclose($stderr);
        }

        proc_close($process);
    }

    private function dropBoot(): void
    {
        if ($this->boot instanceof \CurlHandle) {
            curl_multi_remove_handle($this->multi, $this->boot);
            $this->boot = null;
        }
    }

    /**
     * @param array<string, string> $headers
     * @param list<array<string, mixed>>|null $rows
     */
    private function settled(
        Job $job,
        ?string $error,
        ?int $status = null,
        ?string $body = null,
        array $headers = [],
        ?int $exitCode = null,
        ?string $stdout = null,
        ?string $stderr = null,
        ?string $errorLog = null,
        ?array $rows = null,
        ?string $sqlFile = null,
    ): Settled {
        return new Settled(
            $job,
            $status,
            $body,
            $headers,
            $error,
            $exitCode,
            $stdout,
            $stderr,
            $errorLog,
            $rows,
            $sqlFile,
        );
    }
}
