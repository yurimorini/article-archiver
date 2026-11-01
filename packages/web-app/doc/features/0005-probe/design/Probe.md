# Probe

Module design for the probe. The behavior is [probe.md](../specs/probe.md). This file says what the program is made of and how a run moves through it.

## Intent

`bin/probe` is a thin docker entry. One PHP program inside the existing dev image does the work: it starts `php -S`, overlaps the independent steps, writes the run folder, and stops the server. It does not boot Eleanor to serve HTTP. Eleanor stays the child process.

## Technology

PHP 8.5 CLI on the image `log-read-php`, the image `bin/serve` already uses. The base image provides `curl`, `pdo_sqlite`, and `json`. The probe uses those three. It does not add a Composer package, and it does not use Symfony HttpClient, the console component, or `pcntl`.

`ext-curl` provides `curl_multi`. That is how several HTTP steps share one process with a running import. `proc_open` starts the server and `archive:import` with non-blocking pipes. One poll loop pumps the pipes and ticks `curl_multi`. A fork is not required.

Bash is only the docker entry, as in `bin/serve`. It publishes the port and passes `ELEANOR_BEARER_TOKEN` and the optional step name into the container. It reads `port` from `probe/probe.json` with `python3`, which this repo already uses for `var/extract-log-urls.sh`. The host does not need PHP.

## Approaches

1. **PHP poll loop, curl_multi, proc_open.** Chosen. One process overlaps the import child and the HTTP steps. The extensions are already in the image. Tests can replace the input/output seam and drive the scheduler without Docker.
2. **A Symfony console command in `src/`.** Rejected. The command would live in the product, boot the kernel for the whole run, and still need a child `php -S`. The probe stays out of `src/`.
3. **`pcntl_fork` for the refusal band.** Rejected. A second process model, for a overlap `curl_multi` already gives while `proc_open` is non-blocking.
4. **Shell and `curl`.** Rejected in the spec. Slicing the JSON log and writing the records in the shell spreads the same rules across the script.

## External seam

The caller learns one command.

`packages/web-app/bin/probe` with no argument runs every name in `probe/probe.json` `steps`. `packages/web-app/bin/probe <name>` runs that catalog name. The process exit code is the spec's exit code. The run folder is the only result.

The PHP entry behind that command is `probe/run.php`. Its interface is `main(array $argv): int`. Tests call the scheduler directly. They do not shell out to docker.

## Internal seams

Two seams, because the tests need a second adapter for each.

`Io` is how a job starts and how the loop hears that it finished. The production adapter is curl and `proc_open`. A test adapter finishes jobs in the order the test scripts.

```php
interface Io
{
    public function start(Job $job): void;

    /** @return list<Settled> */
    public function poll(): array;
}
```

`Job` names the work: `server`, `import`, an HTTP step, or `sql`. `Settled` is that job's outcome: an HTTP exchange, an import exit with its output, a SQL row list, or an error string. The secret never appears on either type. `authorization` is already the label `bearer`, `absent`, or `wrong`.

`Clock` is not a seam. Timeouts are arguments of the production `Io`.

One adapter would be a hypothetical seam. The test adapter is the second, so the seam stays.

## Scheduler

`Scheduler` is the deep part. It knows the spec's bands and nothing about curl.

It is given the selected step names, the draw, and an `Io`. It returns the exit code after every selected step has settled. It writes each step file as that step settles, so a killed process still leaves the steps that finished.

Phases:

1. `Io::start` the server job. Poll until it settles. A broken server returns non-zero and starts no step.
2. Start every selected refusal preset, and start `import` when it is selected. Those jobs share the poll loop from the first tick.
3. When `import` has settled, or was not selected, start `archive` if it is selected. When `archive` has settled, start `archive-again` if it is selected. A broken write starts the next write anyway. Each of them uses the first URL of the draw.
4. When every selected write has settled, start `list`, `detail`, `detail-content`, `detail-html`, and `sql` if they are selected. They share the poll loop. They do not wait for a refusal that is still running. The three detail rows look up the id of the first drawn URL and send `GET /api/archives/{id}`, the same path with `includeContent=true`, and `GET /api/archives/{id}.html`. A missing row records an error and sends no request.
5. Poll until every selected step has settled. A job that settles with an error is recorded and does not remove the others.
6. Stop the server. Return 0 when no settled step has an error. Return non-zero when any step has an error.

`archive:import` remains one child process. The catalog does not start one process per URL.

Refusal presets are rows in a table on `Catalog`: method, path, content type, body, and the authorization label. Adding a route later is a row, or a branch in the write chain, not a new class per step. `import` and `sql` are the two jobs that are not HTTP rows. `detail`, `detail-content`, and `detail-html` are the three rows for one archive.

## Production Io

One class owns the multi handle and the child processes.

An HTTP `start` adds an easy handle to `curl_multi`. `poll` calls `curl_multi_exec` and collects finished handles. A transport failure settles that job with `error` set and `response` null. An HTTP status, including 4xx and 5xx, settles the job with the response and `error` null.

`import` and the server are `proc_open` with pipes set to non-blocking. `poll` reads whatever is there. It does not wait for the child to exit before ticking curl. The import job settles when the child exits. Exit 0 or 1, with the summary on stdout, is a settled import and `error` is null. Any other exit settles it with `error` set. The server job settles when the first HTTP response arrives, or with `error` when the boot timeout passes or the port is taken.

`sql` is a PDO open of `var/data/<16 hex of sha256(lowercase email)>.sqlite` with `SQLITE_OPEN_READONLY`. It runs on `start` and settles on the next `poll`, so it overlaps `list`. A missing file or a missing row settles with `rows: []` and `error` null.

Timeouts, as arguments of this adapter: the server boot waits 10 seconds; each HTTP transfer waits 60 seconds; the import child waits 10 minutes. A timeout settles that job with `error` set.

The server is stopped with the process signal the child needs, in a `finally` path, including when the scheduler returns early because the server never came up. The import child is not killed because a later step failed. It is killed only when the probe process itself is leaving and the child is still running.

## The other files

`Draw` is a pure function. `mt_srand($seed)` then a Fisher-Yates shuffle with `mt_rand`. The same seed and the same lines produce the same list on this PHP. Blank lines are dropped first. The result is at most `sample` lines, and `sample` above 10 or below 1 throws before the shuffle. The runner writes that list to `sample.txt` only when a selected step needs the draw.

`Owner` boots `Yumo\Eleanor\Kernel` in `dev` and reads the parameter `eleanor.owner_email`. It does this once, before the server child starts, and shuts the kernel down. It does not parse `services.yaml`. The config `owner`, when present, is compared after `mb_strtolower`. A mismatch throws. The token is read from `getenv('ELEANOR_BEARER_TOKEN')`. Empty throws. Neither value is written to the run folder.

`Record` writes `run.json` and `steps/NN-name.json`. `NN` is the position of the name in the selected list, not the order the jobs finish. HTTP log lines come from a tail of `var/log/eleanor.dev.log` that starts at the file size captured when the server settles. Each settled HTTP step takes the JSON objects whose `extra.request_id` equals that response's `X-Request-Id`. Lines for a request id that has not settled yet stay in the tail buffer.

`probe/run.php` reads argv, builds `Owner`, `Draw`, `Record`, the production `Io`, and `Scheduler`, and returns the exit code.

## Layout

```text
packages/web-app/
  bin/probe                  bash, docker only
  probe/probe.json
  probe/run.php              main(argv): int
  probe/Scheduler.php
  probe/Io.php               interface
  probe/CurlProcessIo.php    production adapter
  probe/Catalog.php          chain order and preset rows
  probe/Draw.php
  probe/Owner.php
  probe/Record.php
  probe/runs/                gitignored
  tests/Probe/DrawTest.php
  tests/Probe/SchedulerTest.php
```

`probe/` is added to the PHP CS finder and to PHPStan, level 8, beside `src/`. The tests live under `tests/`, which both tools already include.

The scheduler tests use a fake `Io`. They cover one selected step, a broken import that still starts `archive`, refusals that are started before the write chain settles, and `list` waiting for the writes. `DrawTest` covers the cap of 10 and a repeated seed. The production `Io` is not covered by PHPUnit. A later run of `bin/probe` is the check that curl and `proc_open` behave.

## What this leaves out

The clean image is not built here. `probe/` is simply not part of that image's copy. No product route changes. `read` is not implemented.
