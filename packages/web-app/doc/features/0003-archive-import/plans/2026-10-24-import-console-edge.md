# Import console edge implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move `archive:import` to `Yumo\Eleanor\Commands\Import` and make one console writer the only caller of Symfony `write` and `writeln`.

**Architecture:** `ImportConsole` takes the command output plus the two existing formatters and writes their text raw. `ImportCommand` keeps `error.log`, builds the writer inside `execute`, and reads as three sections: open, walk, finish. Stream, processor, formatters, and tally stay in `Yumo\Eleanor\Import`.

**Tech Stack:** PHP 8.5, Symfony 8.1 console, PHPUnit 11, `./bin/test`, `./bin/quality`. Docker only.

**Spec:** `packages/web-app/doc/features/0003-archive-import/design/UrlListImport.md`

## Global Constraints

- The archive write stays `ArchiveServiceFactory::create(Owner)` and `ArchiveService::archive(UrlPointer)`. `ArchiveService` is not changed.
- The test seam stays one run of `archive:import`.
- Sentences and the four prefixes stay English.
- `Yumo\Eleanor\Commands\Import` holds the command and the console writer. `Yumo\Eleanor\Import` holds the stream, the processor, the formatters, and the tally.
- The writer is not a container service. The command constructs it when it has an output.
- The writer is the only type that calls `write` or `writeln`, and every one of those calls passes `OutputInterface::OUTPUT_RAW`.
- The walk has no try/catch. An unexpected throwable still propagates. Totals are not printed. `error.log` stays as it was.
- Public classes and methods have JSDoc. Docs and comments are English.
- Do not add a dependency.

## Review Focus

- A row whose value contains a console tag is printed unchanged, including `<info>`. Existing `test_a_markup_line_is_printed_as_written`.
- An unexpected throwable after the file is accepted prints no totals and leaves a pre-existing `error.log` in place. Task 1 adds `test_an_unexpected_throwable_prints_no_totals`.
- An empty `--owner` writes the cause on the error output and prints no `Importing:`. Existing `test_an_empty_owner_fails_before_importing`.
- A finished run that cannot write `error.log` still prints the four totals, omits the details line, and writes `The error log could not be written.` Existing `test_error_log_cannot_be_written`.
- A finished run with no `ERROR` deletes a pre-existing `error.log`. Existing `test_an_empty_file_deletes_error_log`.

---

### Task 1: Console writer and command move

**Files:**
- Create: `packages/web-app/src/Commands/Import/ImportConsole.php`
- Create: `packages/web-app/src/Commands/Import/ImportCommand.php` (move of `packages/web-app/src/Import/ImportCommand.php`)
- Delete: `packages/web-app/src/Import/ImportCommand.php`
- Modify: `packages/web-app/tests/Support/FakeArticleFetcher.php`
- Test: `packages/web-app/tests/Integration/ImportUrlListCommandTest.php`

**Interfaces:**
- Consumes: `ImportSummaryFormatter::heading(string $path): string`, `ImportSummaryFormatter::footer(ImportTally $tally, ?string $errorLogPath): string`, `ResultLineFormatter::blank(): string`, `ResultLineFormatter::line(ImportResult $result): string`, `CandidateStream::candidates()`, `UrlProcessor::process(string $candidate): ImportResult`, `ImportTally`.
- Produces:

```php
namespace Yumo\Eleanor\Commands\Import;

#[Exclude]
final class ImportConsole
{
    public function __construct(OutputInterface $output, ImportSummaryFormatter $summary, ResultLineFormatter $lines)
    public function heading(string $path): void
    public function blank(): void
    public function row(ImportResult $result): void
    public function gap(): void
    public function footer(ImportTally $tally, ?string $errorLogPath): void
    public function cause(string $message): void
}
```

`ImportCommand` moves to the same namespace. `execute` constructs `new ImportConsole($output, $this->summary, $this->lines)`. Private methods:

```php
private function walk(string $file, CandidateStream $stream, string $email, ImportConsole $console): ImportTally
private function finish(ImportTally $tally, ImportConsole $console): int
```

- [x] **Step 1: Pin the unexpected throwable on the current command**

Add `FakeArticleFetcher::willThrowUnexpected(string $url, \Throwable $error): void`. `fetch` throws that throwable. Add this test to `ImportUrlListCommandTest`:

```php
public function test_an_unexpected_throwable_prints_no_totals(): void
{
    file_put_contents('error.log', 'old');
    file_put_contents('one.txt', "https://example.com/a\n");
    $this->fetcher()->willThrowUnexpected('https://example.com/a', new \RuntimeException('The store broke.'));

    $exit = $this->runImport('one.txt', ['--owner' => 'ada@example.com']);

    self::assertSame(1, $exit);
    self::assertStringContainsString("Importing: one.txt\n", $this->tester->getDisplay());
    self::assertStringNotContainsString('Written', $this->tester->getDisplay());
    self::assertSame('old', file_get_contents('error.log'));
}
```

- [x] **Step 2: Run the pin**

Run: `./bin/test web-app -- --filter test_an_unexpected_throwable_prints_no_totals`

Expected: PASS on the current command. The rule is already implemented. A failure here means the assertion is wrong, not that the command needs a new catch.

- [x] **Step 3: Add `ImportConsole` and move `ImportCommand`**

`heading` writes `summary->heading($path)` with `write(..., false, OUTPUT_RAW)`. `blank` writes `lines->blank()` with `writeln(..., OUTPUT_RAW)`. `row` writes `lines->line($result)` the same way. `gap` writes `writeln('', OUTPUT_RAW)`. `footer` writes `summary->footer($tally, $errorLogPath)` with `write(..., false, OUTPUT_RAW)`. `cause` writes the message with `writeln` on `getErrorOutput()` when `$output` is a `ConsoleOutputInterface`, otherwise on `$output`, and passes `OUTPUT_RAW`.

`execute` is the try that opens the owner, the file, and the stream, then `walk`, then `finish`. The catch calls `cause` and returns `Command::FAILURE`. `walk` prints the heading, loops with no try, calls `blank` or `row`, records the tally, and calls `gap` when a row was seen. `finish` keeps the current `error.log` write and delete, calls `cause` for those two failures, calls `footer`, and returns the exit status. `services.yaml` stays as it is. `#[Exclude]` keeps the container from building `ImportConsole`.

- [x] **Step 4: Run the command seam and the quality gate**

Run: `./bin/test web-app -- --filter ImportUrlListCommandTest`

Expected: PASS.

Run: `./bin/quality`

Expected: exit 0.

- [x] **Step 5: Commit**

```bash
git add packages/web-app/src/Commands/Import packages/web-app/src/Import/ImportCommand.php packages/web-app/tests/Support/FakeArticleFetcher.php packages/web-app/tests/Integration/ImportUrlListCommandTest.php packages/web-app/doc/features/0003-archive-import
git commit -m "$(cat <<'EOF'
refactor: print archive import through a console writer

EOF
)"
```

`git add` of the deleted command path stages the deletion. Do not stage unrelated files.
