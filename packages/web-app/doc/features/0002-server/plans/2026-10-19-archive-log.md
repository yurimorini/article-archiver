# Archive Log Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `ArchiveService` writes the archive command on the `domain` channel, a save line names the SQLite file, and `extra.request_id` joins those lines to the HTTP round.

**Architecture:** `ArchiveStore::location()` returns the owner file path, or `null` when the store has no file. `ArchiveServiceFactory` takes the `domain` logger and passes it into `ArchiveService`, which logs five event names and swallows a logger failure. `RequestIdProcessor` copies `EnrichedRequest.requestId` onto `extra.request_id` for every record the `eleanor` handler accepts. `application` stays the HTTP lines.

**Tech Stack:** PHP 8.5, Symfony 8.1, Monolog 3.12, PHPUnit 11, `./bin/test`, `./bin/quality`.

**Spec:** `packages/web-app/doc/features/0002-server/design/ArchiveLog.md`

## Global Constraints

- `application` stays `http.request.start`, `http.request.finish`, and `http.request.failed`. `domain` is the archive command. `ArchiveService` is the first writer on `domain`.
- Messages are the event names. Values sit in `context`. No article HTML, title, email, bearer token, or exception on these lines.
- `url` is `$url->toString()`. `code` is the `ArchiveFailure` value. `store` is `location()` when that value is a string, and the key is absent when `location()` is `null`.
- `store` is on `archive.written` and on `archive.failed` only when `code` is `archive_not_written`. A fetch failure and an empty page do not add `store`.
- `archive.start` runs inside the lock, before `findSummary`. A lookup throw is not an `ArchiveException`, so there is no `archive.failed` for it.
- A failed log write is caught and not rethrown. The archive outcome stays the one the command already returns.
- `location(): ?string` does not open the file and does not throw. SQLite returns the factory path: data directory, slash, SHA-256 of the owner email, `.sqlite`. The in-memory store returns `null`.
- `RequestIdProcessor` reads the current request. An `EnrichedRequest` sets `extra.request_id` to `requestId`. No request, or no attribute, leaves `extra` without the key. It does not throw. It does not copy `userAgent`, `referer`, the URL, or the owner. A `request_id` already in `context` stays there.
- The `eleanor` handler in `dev`, `test`, and `prod` lists the processor. Symfony's channels do not receive it.
- `#[WithMonologChannel('domain')]` is on `ArchiveServiceFactory`. The service stays `#[Exclude]`. The factory passes the logger through `create`.
- No glossary term. No logger passed into `OrchestratorFactory`. Lock lines stay on `application`. No line when the lock wait expires. Docs and comments in English. PHP tests: `./bin/test web-app`. Final gate: `./bin/quality`. Docker only. Commit scope `web-app`, Conventional Commits.
- `feature.md` already links this plan from the Archive log section and the Specs and plans row. Do not add a second link.

## Review Focus

1. `location()` on the SQLite store before any save creates no file. Task 1 `test_location_names_the_sqlite_file_and_memory_has_none`.
2. A `PDOException` from `findSummary` writes only `archive.start`, then the `PDOException` escapes. Task 3 `test_a_pdo_exception_from_find_summary_logs_start_only`.
3. An `Error` from the fetcher writes `archive.start` and `archive.fetch`, not `archive.failed`, then the `Error` escapes. Task 3 `test_an_error_from_the_fetcher_logs_start_and_fetch`.
4. A logger that throws on the success path still returns `Written`. Task 3 `test_a_throwing_logger_still_returns_written`.
5. A logger that throws while logging a failed save still lets `ArchiveException` with `NotWritten` escape. Task 3 `test_a_throwing_logger_on_save_failure_still_throws_not_written`.

---

### Task 1: Store location

**Files:**
- Modify: `packages/web-app/src/Archive/ArchiveStore.php`
- Modify: `packages/web-app/src/Archive/Sqlite/SqliteArchiveStore.php`
- Modify: `packages/web-app/tests/Support/InMemory/InMemoryArchiveStore.php`
- Modify: `packages/web-app/tests/Support/FakeArchiveStore.php`
- Test: `packages/web-app/tests/Archive/ArchiveStoreContractTest.php`

**Interfaces:**
- Produces: `ArchiveStore::location(): ?string`. `SqliteArchiveStore` returns `$this->connection->path`. `InMemoryArchiveStore` returns `null`. `FakeArchiveStore` has `public ?string $location = null` and `location()` returns that property. Calling `location()` does not create a directory or a database file.

- [ ] **Step 1: Write the failing test**

In `ArchiveStoreContractTest`:

```php
public function test_location_names_the_sqlite_file_and_memory_has_none(): void
{
    $owner = new Owner('ada@example.com');
    $memory = $this->factory('memory')->create($owner);
    $sqlite = $this->factory('sqlite')->create($owner);

    self::assertNull($memory->location());
    self::assertSame(
        $this->directory . '/' . hash('sha256', 'ada@example.com') . '.sqlite',
        $sqlite->location(),
    );
    self::assertSame([], $this->databaseFiles());
}
```

- [ ] **Step 2: Run `./bin/test web-app -- --filter ArchiveStoreContractTest::test_location_names_the_sqlite_file_and_memory_has_none`.** Expected: FAIL, `location` is unknown.

- [ ] **Step 3: Implement `location(): ?string`** on `ArchiveStore` and the three classes in Interfaces. JSDoc: the file this store writes, or `null` when it has no file. It does not open the file.

- [ ] **Step 4: Run `./bin/test web-app -- --filter ArchiveStoreContractTest`.** Expected: PASS.

- [ ] **Step 5: Commit** `feat(web-app): name the archive store file`.

### Task 2: Request id processor

**Files:**
- Create: `packages/web-app/src/Request/RequestIdProcessor.php`
- Modify: `packages/web-app/config/packages/monolog.yaml` (the `eleanor` handler under `when@dev`, `when@test`, and `when@prod`)
- Test: `packages/web-app/tests/Request/RequestIdProcessorTest.php`

**Interfaces:**
- Produces: `final class RequestIdProcessor`. `__construct(RequestStack $stack)`. `__invoke(LogRecord $record): LogRecord`. Sets `$record->extra['request_id']` from `EnrichedRequest` on the current request and returns that same record. No request, or no `EnrichedRequest` attribute, returns the record with `extra` unchanged. Does not write `userAgent` or `referer`. Does not change `context`.
- Each `eleanor` handler gains:

```yaml
processors:
    - Yumo\Eleanor\Request\RequestIdProcessor
```

- [ ] **Step 1: Write the failing test**

`RequestIdProcessorTest` does not boot the kernel. Build records with `new LogRecord(new \DateTimeImmutable(), 'domain', Level::Info, 'archive.fetch', ['request_id' => 'from-context'])`.

```php
public function test_copies_the_stored_id_onto_extra(): void
{
    $request = Request::create('/api/archives');
    $request->attributes->set(EnrichedRequest::class, new EnrichedRequest('req-1', 'Agent', 'https://ref.example'));
    $stack = new RequestStack();
    $stack->push($request);

    $record = (new RequestIdProcessor($stack))($this->record());

    self::assertSame(['request_id' => 'req-1'], $record->extra);
    self::assertSame(['request_id' => 'from-context'], $record->context);
}

public function test_an_empty_stack_leaves_extra_without_the_id(): void
{
    $record = (new RequestIdProcessor(new RequestStack()))($this->record());

    self::assertSame([], $record->extra);
}

public function test_a_request_without_the_attribute_leaves_extra_without_the_id(): void
{
    $stack = new RequestStack();
    $stack->push(Request::create('/api/archives'));

    $record = (new RequestIdProcessor($stack))($this->record());

    self::assertSame([], $record->extra);
}
```

`record()` returns the `LogRecord` above. The test with `Agent` and `https://ref.example` proves those values are not copied, because `extra` is exactly `['request_id' => 'req-1']`.

- [ ] **Step 2: Run `./bin/test web-app -- --filter RequestIdProcessorTest`.** Expected: FAIL, class `RequestIdProcessor` not found.

- [ ] **Step 3: Implement** the class in Interfaces. JSDoc on the class and on `__invoke`. Add the `processors` list to all three `eleanor` handlers.

- [ ] **Step 4: Run `./bin/test web-app -- --filter RequestIdProcessorTest`.** Expected: PASS.

- [ ] **Step 5: Commit** `feat(web-app): copy the request id onto eleanor log records`.

### Task 3: Archive service lines

**Files:**
- Modify: `packages/web-app/src/Archive/ArchiveService.php`
- Modify: `packages/web-app/src/Archive/ArchiveServiceFactory.php`
- Modify: `packages/web-app/tests/Archive/ArchiveServiceTest.php`
- Modify: `packages/web-app/tests/Archive/ArchiveServiceFactoryTest.php`
- Modify: `packages/web-app/tests/Api/Resolver/ArchiveServiceResolverTest.php`

**Interfaces:**
- Consumes: `ArchiveStore::location(): ?string` from Task 1. `RequestIdProcessor` is not called by the service.
- Produces: `ArchiveService::__construct(ArchiveStore $store, ArticleFetcher $fetcher, ArchiveLock $lock, LoggerInterface $logger, ArchiveFailureMapper $failures = new ArchiveFailureMapper())`. `ArchiveServiceFactory::__construct(ArticleFetcher $fetcher, ArchiveStoreFactory $stores, ArchiveLockFactory $locks, LoggerInterface $logger)` with `#[WithMonologChannel('domain')]`. `create` passes `$this->logger` into the service.

- [ ] **Step 1: Write the failing tests**

`ArchiveServiceTest` keeps a `RecordingLogger` (an `AbstractLogger` in that test file) and passes it from `setUp` through `serviceWith` and the two direct `new ArchiveService(...)` calls. `RecordingLogger::log` appends `[$level, $message, $context]` to a public list. `level` is the string `info` or `warning`.

`ArchiveServiceFactoryTest` and `ArchiveServiceResolverTest` pass `new NullLogger()` as the new argument.

```php
public function test_a_miss_logs_start_fetch_and_written(): void
{
    $this->store->location = '/data/ada.sqlite';
    $this->fetcher->willReturn(self::URL, PipelineFixtures::success());

    $result = $this->service->archive(new UrlPointer(self::URL));

    self::assertSame(ArchiveOutcome::Written, $result->outcome);
    self::assertSame([
        ['info', 'archive.start', ['url' => self::URL]],
        ['info', 'archive.fetch', ['url' => self::URL]],
        ['info', 'archive.written', ['url' => self::URL, 'store' => '/data/ada.sqlite']],
    ], $this->logger->records);
}

public function test_a_miss_without_a_location_omits_store(): void
{
    $this->fetcher->willReturn(self::URL, PipelineFixtures::success());

    $this->service->archive(new UrlPointer(self::URL));

    self::assertSame(
        ['info', 'archive.written', ['url' => self::URL]],
        $this->logger->records[2],
    );
}

public function test_a_hit_logs_start_and_already_stored(): void
{
    $this->fetcher->willReturn(self::URL, PipelineFixtures::success());
    $this->service->archive(new UrlPointer(self::URL));
    $this->logger->records = [];

    $result = $this->service->archive(new UrlPointer(self::URL));

    self::assertSame(ArchiveOutcome::AlreadyStored, $result->outcome);
    self::assertSame([
        ['info', 'archive.start', ['url' => self::URL]],
        ['info', 'archive.already_stored', ['url' => self::URL]],
    ], $this->logger->records);
    self::assertSame(1, $this->fetcher->fetchCount());
}

public function test_a_mapped_fetch_failure_logs_failed_without_store(): void
{
    $this->store->location = '/data/ada.sqlite';
    $this->fetcher->willThrow(self::URL, new OrchestratorException(OrchestratorError::Fetch));

    try {
        $this->service->archive(new UrlPointer(self::URL));
        self::fail('Expected an ArchiveException.');
    } catch (ArchiveException) {
        self::assertSame([
            ['info', 'archive.start', ['url' => self::URL]],
            ['info', 'archive.fetch', ['url' => self::URL]],
            ['warning', 'archive.failed', ['url' => self::URL, 'code' => 'unexpected']],
        ], $this->logger->records);
    }
}

public function test_an_empty_page_logs_failed_without_store(): void
{
    $this->store->location = '/data/ada.sqlite';
    $this->fetcher->willReturn(self::URL, PipelineFixtures::noContent());

    try {
        $this->service->archive(new UrlPointer(self::URL));
        self::fail('Expected an ArchiveException.');
    } catch (ArchiveException) {
        self::assertSame(
            ['warning', 'archive.failed', ['url' => self::URL, 'code' => 'sanitized_empty']],
            $this->logger->records[2],
        );
    }
}

public function test_a_pdo_exception_from_save_logs_failed_with_store(): void
{
    $this->store->location = '/data/ada.sqlite';
    $this->store->saveFailure = new \PDOException('locked');
    $this->fetcher->willReturn(self::URL, PipelineFixtures::success());

    try {
        $this->service->archive(new UrlPointer(self::URL));
        self::fail('Expected an ArchiveException.');
    } catch (ArchiveException $exception) {
        self::assertSame(ArchiveFailure::NotWritten, $exception->failure);
        self::assertSame(
            ['warning', 'archive.failed', ['url' => self::URL, 'code' => 'archive_not_written', 'store' => '/data/ada.sqlite']],
            $this->logger->records[2],
        );
    }
}

public function test_a_directory_exception_from_save_logs_failed_with_store(): void
{
    $this->store->location = '/data/ada.sqlite';
    $this->store->saveFailure = new DirectoryException('The data directory could not be created.');
    $this->fetcher->willReturn(self::URL, PipelineFixtures::success());

    try {
        $this->service->archive(new UrlPointer(self::URL));
        self::fail('Expected an ArchiveException.');
    } catch (ArchiveException $exception) {
        self::assertSame(ArchiveFailure::NotWritten, $exception->failure);
        self::assertSame(
            ['warning', 'archive.failed', ['url' => self::URL, 'code' => 'archive_not_written', 'store' => '/data/ada.sqlite']],
            $this->logger->records[2],
        );
    }
}

public function test_a_save_failure_without_a_location_omits_store(): void
{
    $this->store->saveFailure = new \PDOException('locked');
    $this->fetcher->willReturn(self::URL, PipelineFixtures::success());

    try {
        $this->service->archive(new UrlPointer(self::URL));
        self::fail('Expected an ArchiveException.');
    } catch (ArchiveException) {
        self::assertSame(
            ['warning', 'archive.failed', ['url' => self::URL, 'code' => 'archive_not_written']],
            $this->logger->records[2],
        );
    }
}

public function test_a_pdo_exception_from_find_summary_logs_start_only(): void
{
    $this->store->findSummaryFailure = new \PDOException('locked');

    try {
        $this->service->archive(new UrlPointer(self::URL));
        self::fail('Expected a PDOException.');
    } catch (\PDOException) {
        self::assertSame([
            ['info', 'archive.start', ['url' => self::URL]],
        ], $this->logger->records);
    }
}

public function test_an_error_from_the_fetcher_logs_start_and_fetch(): void
{
    $this->fetcher->willThrow(self::URL, new \Error('boom'));

    try {
        $this->service->archive(new UrlPointer(self::URL));
        self::fail('Expected an Error.');
    } catch (\Error) {
        self::assertSame([
            ['info', 'archive.start', ['url' => self::URL]],
            ['info', 'archive.fetch', ['url' => self::URL]],
        ], $this->logger->records);
    }
}

public function test_a_throwing_logger_still_returns_written(): void
{
    $this->fetcher->willReturn(self::URL, PipelineFixtures::success());
    $service = new ArchiveService($this->store, $this->fetcher, $this->lock, new ThrowingLogger());

    $result = $service->archive(new UrlPointer(self::URL));

    self::assertSame(ArchiveOutcome::Written, $result->outcome);
}

public function test_a_throwing_logger_on_save_failure_still_throws_not_written(): void
{
    $this->store->saveFailure = new \PDOException('locked');
    $this->fetcher->willReturn(self::URL, PipelineFixtures::success());
    $service = new ArchiveService($this->store, $this->fetcher, $this->lock, new ThrowingLogger());

    try {
        $service->archive(new UrlPointer(self::URL));
        self::fail('Expected an ArchiveException.');
    } catch (ArchiveException $exception) {
        self::assertSame(ArchiveFailure::NotWritten, $exception->failure);
        self::assertInstanceOf(\PDOException::class, $exception->getPrevious());
    }
}
```

`ThrowingLogger` is an `AbstractLogger` in the same test file. `log` throws `\RuntimeException` with message `disk full`.

- [ ] **Step 2: Run `./bin/test web-app -- --filter 'ArchiveServiceTest::test_a_miss_logs_start_fetch_and_written|ArchiveServiceFactoryTest'`.** Expected: FAIL, `ArchiveService` does not accept the logger.

- [ ] **Step 3: Implement** the constructor signatures in Interfaces. In `archiveLocked`, log `archive.start` before `findSummary`. A stored summary logs `archive.already_stored` and returns. A miss logs `archive.fetch`, then fetches. An `ArchiveException` from the fetch logs `archive.failed` with `url` and `code` and is rethrown, with no `store`. After `save` returns, log `archive.written` with `url` and `store` when `location()` is a string. A `DirectoryException` or a `PDOException` from `save` logs `archive.failed` with `code` `archive_not_written` and `store` when `location()` is a string, then throws `ArchiveException` `NotWritten` with that exception as the previous. Each logger call sits in `try` / `catch (\Throwable)` with an empty catch. The comment on the catch: a failed write must not change the archive outcome. Do not catch the lookup failure. Pass `new NullLogger()` from the two test factories.

- [ ] **Step 4: Run `./bin/test web-app` and `./bin/quality`.** Expected: both exit 0.

- [ ] **Step 5: Commit** `feat(web-app): log the archive command on the domain channel`.
