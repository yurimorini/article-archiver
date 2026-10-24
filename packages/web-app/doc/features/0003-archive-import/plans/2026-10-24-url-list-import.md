# URL List Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `archive:import` archives each URL in a one-field file for the given owner and prints the aligned result lines and totals.

**Architecture:** The command walks a `CandidateStream` from a factory. Ticket 01's factory returns the line reader. A processor guards, then calls `ArchiveService`. A line formatter and a summary formatter return text. A tally counts. The command prints and writes `error.log`. The loop is a straight `foreach`.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit 11, `yumo/article-reader` `UrlGuard`, `./bin/test`, `./bin/quality`.

**Spec:** `packages/web-app/doc/features/0003-archive-import/design/UrlListImport.md` and `packages/web-app/doc/features/0003-archive-import/specs/archive-import.md`. Ticket [01](../tickets/01-import-a-url-list.md).

## Global Constraints

- Command name `archive:import`. One argument, `file`. One option, `--owner`, required. Do not register `--field`, `--index`, or `--parallel`.
- `ArchiveService` does not change. The command uses `ArchiveServiceFactory::create(new Owner($email))` and `archive(new UrlPointer($candidate))`.
- The command depends on `CandidateStreamFactory`. It does not name `LineCandidateStream`.
- Label column is 16 characters, `str_pad($label, 16, ' ', STR_PAD_RIGHT)`. The value starts at column 17. A blank value is `--`.
- Prefixes are `SKIPPED`, `ARCHIVED`, `ALREADY STORED`, and `ERROR`. Totals are `Written N`, `Already stored N`, `Failed N`, and `Skipped N`.
- Heading is `Importing: ` plus the path argument as given. It is printed only after the file is accepted.
- `error.log` is that name in the process working directory. A line is the trimmed URL, a tab, and the failure code. The summary line is `For error details see ` plus the absolute path. Symfony logs are not used.
- `url_syntax`, `url_policy`, and `url_rejected` are `SKIPPED`. They are not written to `error.log` and they do not call `ArchiveService` when the guard rejects first.
- An `ArchiveException` with any other code is `ERROR`. The later rows still run. Any other throwable propagates. No totals. `error.log` stays as it was.
- Early failures exit `1`, print no `Importing:`, and leave a pre-existing `error.log` in place. A finished run with no `ERROR` exits `0` and deletes a pre-existing `error.log`.
- Empty owner message is `The owner email is empty.` Unreadable-file message is `The import file could not be read.`
- Downloads are one `foreach`. Do not add a cap of 1.
- A comma stays inside the line. This ticket does not parse CSV and does not refuse a binary.
- Tests boot the kernel the way `bin/console` does: `ArchiveDirectories::isolate()` before boot, `new Application($kernel)`, `ApplicationTester`. The fetcher is the existing `FakeArticleFetcher`. The guard is the real `UrlGuard` with `FixedSsrfUrlValidator` from the test container, so DNS is not used.
- Docs and comments in English. Tests: `./bin/test web-app`. Final gate: `./bin/quality`. Docker only. Commit scope `web-app`, Conventional Commits.
- `feature.md` already links this plan. Do not add a second link.

## Review Focus

1. A line of only spaces is one `SKIPPED` row with value `--`, and the fetcher is not called. Task 4 `test_a_line_of_spaces_is_skipped`.
2. `HTTPS://Example.com/A` is stored and printed with that scheme and host case. Task 5 `test_scheme_case_is_kept`.
3. A CRLF file does not put a CR in the stored URL. Task 5 `test_crlf_does_not_stay_in_the_url`.
4. `not,a,url` is one `SKIPPED` row whose value is `not,a,url`, not three rows. Task 4 `test_a_comma_stays_in_the_line`.
5. A pre-seeded `error.log` survives a missing file and is deleted after an empty file succeeds. Task 1 `test_a_missing_file_leaves_error_log` and Task 2 `test_an_empty_file_deletes_error_log`.

---

### Task 1: The command fails before it reads

**Files:**
- Create: `packages/web-app/src/Import/ImportCommand.php`
- Create: `packages/web-app/tests/Integration/ImportUrlListCommandTest.php`
- Test: `packages/web-app/tests/Integration/ImportUrlListCommandTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `#[AsCommand(name: 'archive:import')] final class ImportCommand extends Command`. Argument `file`. Option `--owner`, required. `execute` returns `1` for an empty owner or an unreadable path. Constructor may be empty until Task 2.

- [ ] **Step 1: Write the failing test**

`ImportUrlListCommandTest` extends `WebTestCase`. `setUp` calls `ArchiveDirectories::isolate()` before `parent::setUp()`. Each test `chdir`s to a fresh directory and restores the previous cwd afterward. A helper runs `new Application(static::$kernel)` and `ApplicationTester`.

`test_an_empty_owner_fails_before_importing`: `--owner=` and a path exit `1`. Standard output is `''`. Error output contains `The owner email is empty.` and does not contain `Importing:`.

`test_a_missing_file_leaves_error_log`: write `error.log` with `old` in the cwd, pass a missing path and `--owner=ada@example.com`. Exit `1`. `error.log` is still `old`. Output does not contain `Importing:`. Error output contains `The import file could not be read.`

`test_a_directory_is_not_a_file`: the path is a directory. Same early failure.

`test_an_unknown_option_exits_1`: `--parallel=1` exits `1` and does not print `Importing:`.

- [ ] **Step 2: Run `./bin/test web-app -- --filter ImportUrlListCommandTest`.** Expected: FAIL, command `archive:import` is not found.

- [ ] **Step 3: Add `ImportCommand`.** Empty `--owner` and a path that `is_file` rejects throw `\RuntimeException` with the two messages above. `execute` catches that exception, writes it to the error output, and returns `1`. Do not open a candidate stream yet.

- [ ] **Step 4: Run `./bin/test web-app -- --filter ImportUrlListCommandTest`.** Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Import/ImportCommand.php packages/web-app/tests/Integration/ImportUrlListCommandTest.php
git commit -m "$(cat <<'EOF'
feat(web-app): reject an import before reading

EOF
)"
```

### Task 2: An empty file prints the summary

**Files:**
- Create: `packages/web-app/src/Import/CandidateStream.php`
- Create: `packages/web-app/src/Import/CandidateStreamFactory.php`
- Create: `packages/web-app/src/Import/LineCandidateStream.php`
- Create: `packages/web-app/src/Import/LineCandidateStreamFactory.php`
- Create: `packages/web-app/src/Import/ImportTally.php`
- Create: `packages/web-app/src/Import/ImportSummaryFormatter.php`
- Modify: `packages/web-app/src/Import/ImportCommand.php`
- Modify: `packages/web-app/config/services.yaml`
- Test: `packages/web-app/tests/Integration/ImportUrlListCommandTest.php`

**Interfaces:**
- Consumes: `ImportCommand` from Task 1
- Produces: `interface CandidateStream { /** @return \Generator<int, string|null> */ public function candidates(): \Generator; }`
- Produces: `interface CandidateStreamFactory { public function open(string $path): CandidateStream; }`
- Produces: `LineCandidateStreamFactory::open(string $path): CandidateStream`. `open` throws `\RuntimeException` `The import file could not be read.` when the path is not a readable file. The stream yields `null` for a blank trimmed line and the trimmed string otherwise. ASCII space and tab only.
- Produces: `ImportTally::skip(): void`, `record(ImportResult $result): void`, `written(): int`, `alreadyStored(): int`, `failed(): int`, `skipped(): int`, `errors(): list<array{url: string, code: string}>`. `ImportResult` arrives in Task 3. Until then `skip` is the only write `record` is not called for an empty file.
- Produces: `ImportSummaryFormatter::heading(string $path): string` returns `"Importing: {$path}\n\n"`. `footer(ImportTally $tally, ?string $errorLogPath): string` returns the four total lines and, when `$errorLogPath` is not null, a leading blank line and `For error details see {$errorLogPath}`.
- The container alias `CandidateStreamFactory` is `LineCandidateStreamFactory`.

- [ ] **Step 1: Write the failing test**

`test_an_empty_file_deletes_error_log`: cwd contains `error.log` with `old`. The file argument is an empty file. `--owner=ada@example.com`. Exit `0`. Standard output is `Importing: {$path}` then a blank line then `Written 0`, `Already stored 0`, `Failed 0`, `Skipped 0`. `error.log` does not exist. The fetcher count is `0`.

- [ ] **Step 2: Run `./bin/test web-app -- --filter test_an_empty_file_deletes_error_log`.** Expected: FAIL.

- [ ] **Step 3: Add the stream, the tally, and the summary formatter.** `ImportCommand` receives `CandidateStreamFactory` and `ImportSummaryFormatter`. After the path is a readable file, print `heading`, walk `candidates()`, call `skip` and print nothing else for an empty file, delete `error.log` when `failed()` is `0`, print `footer` with a null log path, return `0`.

- [ ] **Step 4: Run `./bin/test web-app -- --filter 'test_an_empty_file_deletes_error_log|test_a_missing_file_leaves_error_log'`.** Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Import packages/web-app/config/services.yaml packages/web-app/tests/Integration/ImportUrlListCommandTest.php
git commit -m "$(cat <<'EOF'
feat(web-app): summarize an empty url import

EOF
)"
```

### Task 3: One URL is archived

**Files:**
- Create: `packages/web-app/src/Import/ImportOutcome.php`
- Create: `packages/web-app/src/Import/ImportResult.php`
- Create: `packages/web-app/src/Import/UrlProcessor.php`
- Create: `packages/web-app/src/Import/ResultLineFormatter.php`
- Create: `packages/web-app/tests/Support/FixedSsrfUrlValidator.php`
- Modify: `packages/web-app/config/services.yaml`
- Modify: `packages/web-app/config/services_test.yaml`
- Modify: `packages/web-app/src/Import/ImportTally.php`
- Modify: `packages/web-app/src/Import/ImportCommand.php`
- Test: `packages/web-app/tests/Integration/ImportUrlListCommandTest.php`

**Interfaces:**
- Consumes: `CandidateStream`, `ImportTally`, `ImportSummaryFormatter`, `ArchiveServiceFactory`, `UrlGuard`
- Produces: `enum ImportOutcome { case Skipped; case Archived; case AlreadyStored; case Failed; }`
- Produces: `final readonly class ImportResult { public function __construct(public ImportOutcome $outcome, public ?string $url, public ?string $code = null) {} }`
- Produces: `UrlProcessor::__construct(UrlGuard $guard, ArchiveService $archives)` and `process(string $candidate): ImportResult`. Guard rejection returns `Skipped` and does not call `archive`. `Written` returns `Archived`. `AlreadyStored` returns `AlreadyStored`. Another `ArchiveException` returns `Failed` with `$failure->failure->value`.
- Produces: `ResultLineFormatter::blank(): string` and `line(ImportResult $result): string`. `blank` is `str_pad('SKIPPED', 16) . '--'`. `line` pads `ARCHIVED`, `ALREADY STORED`, `ERROR`, or `SKIPPED` and appends `$result->url`.
- `services.yaml` registers `UrlGuard` with no validator argument, so the default validator is used outside tests. `services_test.yaml` binds `SsrfUrlValidator` to `FixedSsrfUrlValidator`, whose `validate` returns `['1.1.1.1']`.

- [ ] **Step 1: Write the failing test**

`test_one_url_is_archived`: file contents `https://example.com/a\n`. `willReturn` that URL with `PipelineFixtures::article('Title')`. Exit `0`. One row is `str_pad('ARCHIVED', 16) . 'https://example.com/a'`. Totals are `Written 1`, `Already stored 0`, `Failed 0`, `Skipped 0`. `fetchCount('https://example.com/a')` is `1`. No `error.log`.

- [ ] **Step 2: Run `./bin/test web-app -- --filter test_one_url_is_archived`.** Expected: FAIL.

- [ ] **Step 3: Add the processor, the line formatter, and the tally `record`.** The command builds the service for `new Owner($email)`, skips null candidates through `blank` and `skip`, and for a string calls `process`, prints `line`, and `record`s. `#[Exclude]` on the result and the outcome.

- [ ] **Step 4: Run `./bin/test web-app -- --filter 'test_one_url_is_archived|test_an_empty_file_deletes_error_log'`.** Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Import packages/web-app/config/services.yaml packages/web-app/config/services_test.yaml packages/web-app/tests/Support/FixedSsrfUrlValidator.php packages/web-app/tests/Integration/ImportUrlListCommandTest.php
git commit -m "$(cat <<'EOF'
feat(web-app): archive one imported url

EOF
)"
```

### Task 4: Skips

**Files:**
- Modify: `packages/web-app/src/Import/UrlProcessor.php`
- Test: `packages/web-app/tests/Integration/ImportUrlListCommandTest.php`

**Interfaces:**
- Consumes: `UrlProcessor::process`, `ResultLineFormatter`, `ImportTally::skip` and `record`
- Produces: no new types

- [ ] **Step 1: Write the failing test**

`test_a_line_of_spaces_is_skipped`: file `"   \n"`. Output row is `str_pad('SKIPPED', 16) . '--'`. `Skipped 1`. Fetcher count `0`.

`test_a_rejected_url_is_skipped`: file `not-a-url\n`. Row is `str_pad('SKIPPED', 16) . 'not-a-url'`. `Skipped 1`. Fetcher count `0`. No `error.log`.

`test_a_comma_stays_in_the_line`: file `not,a,url\n`. One data row, value `not,a,url`. Not three rows.

- [ ] **Step 2: Run `./bin/test web-app -- --filter 'test_a_line_of_spaces_is_skipped|test_a_rejected_url_is_skipped|test_a_comma_stays_in_the_line'`.** Expected: FAIL.

- [ ] **Step 3: Confirm the trim stage yields null for a spaces-only line, and `process` maps `UrlGuardException` syntax, policy, and rejected to `Skipped` without calling `archive`.** A policy URL is not a separate test. `http://user:pass@example.com/a` is covered by the same branch as `not-a-url` if that branch is the three `UrlGuardError` cases.

- [ ] **Step 4: Run the same filter.** Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Import/UrlProcessor.php packages/web-app/src/Import/LineCandidateStream.php packages/web-app/tests/Integration/ImportUrlListCommandTest.php
git commit -m "$(cat <<'EOF'
feat(web-app): skip blank and rejected import lines

EOF
)"
```

### Task 5: Already stored, case, and CRLF

**Files:**
- Test: `packages/web-app/tests/Integration/ImportUrlListCommandTest.php`

**Interfaces:**
- Consumes: `UrlProcessor`, `ArchiveService::archive`
- Produces: no new types

- [ ] **Step 1: Write the failing test**

`test_a_second_run_does_not_fetch`: first run of `https://example.com/a\n` with a registered article. Second `ApplicationTester` run of the same path. Second output row is `str_pad('ALREADY STORED', 16) . 'https://example.com/a'`. `Already stored 1`. `fetchCount` for that URL is `1` after both runs.

`test_two_copies_fetch_once`: one file with that URL twice. Rows are `ARCHIVED` then `ALREADY STORED`. `fetchCount` is `1`. Totals `Written 1` and `Already stored 1`.

`test_scheme_case_is_kept`: file `HTTPS://Example.com/A\n`. `willReturn` uses that exact string. The row value and a following run's already-stored value are `HTTPS://Example.com/A`. `fetchCount('HTTPS://Example.com/A')` is `1`.

`test_crlf_does_not_stay_in_the_url`: file `"https://example.com/a\r\n"`. The printed value is `https://example.com/a` with no CR. `fetchCount('https://example.com/a')` is `1`.

`test_owner_case_is_the_same_owner`: first run `--owner=Owner@Example.com`. Second run `--owner=owner@example.com` on the same URL. The second row is `ALREADY STORED`. `fetchCount` is `1`.

- [ ] **Step 2: Run `./bin/test web-app -- --filter 'test_a_second_run_does_not_fetch|test_two_copies_fetch_once|test_scheme_case_is_kept|test_crlf_does_not_stay_in_the_url|test_owner_case_is_the_same_owner'`.** Expected: FAIL until the processor returns `AlreadyStored` and the line reader strips CR.

- [ ] **Step 3: No new type.** `LineCandidateStream` strips the line ending before trim. `UrlProcessor` maps `ArchiveOutcome::AlreadyStored`. `Owner` already folds the email.

- [ ] **Step 4: Run the same filter.** Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Import packages/web-app/tests/Integration/ImportUrlListCommandTest.php
git commit -m "$(cat <<'EOF'
feat(web-app): report an import that is already stored

EOF
)"
```

### Task 6: Failures and error.log

**Files:**
- Modify: `packages/web-app/src/Import/ImportCommand.php`
- Modify: `packages/web-app/src/Import/ImportTally.php`
- Test: `packages/web-app/tests/Integration/ImportUrlListCommandTest.php`

**Interfaces:**
- Consumes: `ImportTally::errors(): list<array{url: string, code: string}>`, `ImportResult::$code`
- Produces: the command writes `error.log` after the loop and before `footer`. A write failure of that file prints the four totals, omits the details line, writes `The error log could not be written.` to the error output, and returns `1`.

- [ ] **Step 1: Write the failing test**

`test_a_failed_url_does_not_stop_the_next`: file `https://example.com/a\nhttps://example.com/b\n`. `willThrow` the first with `new ArchiveException(ArchiveFailure::Transport)`. `willReturn` the second. Exit `1`. Rows are `ERROR` then `ARCHIVED`, values in column 17. Totals `Written 1`, `Failed 1`. `error.log` is `https://example.com/a\ttransport\n`. Output ends with `For error details see {$cwd}/error.log`. `fetchCount` is `1` for each URL.

`test_error_log_cannot_be_written`: same one failing URL, cwd replaced by a directory the test makes mode `0555` after writing the import file elsewhere and passing that path. Exit `1`. The four totals are present. `For error details see` is absent. Error output contains `The error log could not be written.`

- [ ] **Step 2: Run `./bin/test web-app -- --filter 'test_a_failed_url_does_not_stop_the_next|test_error_log_cannot_be_written'`.** Expected: FAIL.

- [ ] **Step 3: Record `Failed` with the code.** After the loop, if `errors()` is not empty, write `error.log` in the cwd. On failure to write, set the exit status to `1` and pass a null path to `footer`. A thrown `ArchiveException` does not leave the loop.

- [ ] **Step 4: Run the same filter.** Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/web-app/src/Import/ImportCommand.php packages/web-app/src/Import/ImportTally.php packages/web-app/src/Import/UrlProcessor.php packages/web-app/tests/Integration/ImportUrlListCommandTest.php
git commit -m "$(cat <<'EOF'
feat(web-app): record a failed url import

EOF
)"
```

### Task 7: Gate

**Files:**
- Test: `packages/web-app/tests/Integration/ImportUrlListCommandTest.php`

**Interfaces:**
- Consumes: the command from Task 6
- Produces: no new types

- [ ] **Step 1: Run `./bin/quality`.** Expected: PASS. Fix only what this plan's files violate.

- [ ] **Step 2: Commit, if the gate changed files**

```bash
git add packages/web-app
git commit -m "$(cat <<'EOF'
style(web-app): format the url import

EOF
)"
```

Skip the commit when `git status` is clean.
