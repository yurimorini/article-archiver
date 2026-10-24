# URL list import

Module design for ticket [01 — Import a URL list](../tickets/01-import-a-url-list.md). The contract is [archive-import.md](../specs/archive-import.md). This ticket is the one-field file. Comma-separated columns, the binary refusal, and `--parallel` are the later tickets. They must be able to replace a piece of this shape without rewriting the print or the tally. This ticket adds no glossary term.

The work is one Symfony command and a handful of types beside it. It is not a change to `ArchiveService`. The command loop depends on one stream of candidates. Ticket 01's line reader is the first implementation of that stream. A later reader, including CSV, is another implementation. The loop keeps receiving the same candidates.

## Intent

An operator points `archive:import` at a text file of one URL per line and names the owner by email. Eleanor archives each accepted URL with the same write as `POST /api/archives`, prints one result line per data row, then prints four totals. Failures of that write also land in `error.log` in the working directory.

## Goals

- One file and a required `--owner` produce archives for that owner.
- Each data row prints `SKIPPED`, `ARCHIVED`, `ALREADY STORED`, or `ERROR` in a label column, and the URL or `--` in a value column.
- A blank row prints `SKIPPED` and `--`, and does not call the archive write.
- An already stored URL is not fetched.
- A failed write does not stop the later rows.
- Downloads run one after another.

## Non-goals

- `--field`, `--index`, and `--parallel`. The command does not register them. Symfony's unknown-option failure is an early failure.
- Classifying a comma-separated file. A comma inside a line stays part of that line.
- Refusing a binary. Every readable file is read as text.
- A line that updates while a download runs. That is slice 2.
- A parser type for the arguments. The command reads the argument and the option itself.

## Anti-goals

- Printing the URL when the download starts, then changing that line.
- Treating `url_syntax`, `url_policy`, or `url_rejected` as `ERROR`.
- Writing those three codes to `error.log`.
- Deleting or rewriting `error.log` when the run fails before any row is processed.
- Calling Eleanor over HTTP.

## Constraints

- The archive write is `ArchiveServiceFactory::create(Owner)` and `ArchiveService::archive(UrlPointer)`. The service is not changed.
- The test seam is one run of the command. It reads the exit status, standard output, the error output's presence, `error.log`, and the archives stored for that owner. An `ArticleFetcher` double replaces the network. The URL guard that rejects a cell is the library `UrlGuard`, not that double.
- The owner email is folded the way `Owner` already folds it. An empty email fails before the file is opened.
- Sentences and the four prefixes are English.
- The types live in one folder with the command. They are not spread across the archive namespace.
- The command names the candidate stream and does not name a line reader or a CSV reader.

## Approaches

Three ways were considered.

The command reads, guards, archives, and prints, in one class. Ticket 04, which adds a cap on downloads in flight, would rewrite that class, including the printing. Rejected.

The command prints and a processor archives. A straight `foreach` runs one URL after another. Ticket 04 replaces that `foreach`. The printing stays. This is the chosen way. The HTTP route already keeps the controller on the edge and the write behind it.

Printing the URL when the download starts. That needs a line that changes in place, which is slice 2. Rejected for this ticket. The command prints after the result of the row is known.

## How the run goes

The command is a Symfony command named `archive:import`. The file path is the only argument. `--owner` is required.

An empty `--owner` fails before the file is opened. A missing path, a directory, or an unreadable file fails the same way. So does an unknown option. An early failure writes a cause to the error output, prints no result lines and no totals, leaves a pre-existing `error.log` untouched, archives nothing, and exits with status 1. Status 1 is the single failure status for this ticket. That number is a call made here: the contract requires one shared non-zero status and does not pick the digit.

A readable file is opened. The command then walks a candidate stream. It does not open a second path into the file.

The stream is a port. Each value is an empty candidate or a trimmed string. Ticket 01 has one implementation, the line reader. Behind that port the implementation is nested. One stage yields each raw line. The line ending, LF or CRLF, is not part of the line. The next stage strips leading and trailing ASCII spaces and tabs and yields the candidate. Neither stage calls the guard or archives. The command does not see the stages.

A later implementation of the same port can read a comma-separated file, choose the column, drop the header, and still yield empty candidates and trimmed strings. The command loop stays.

```text
for candidate in second generator
    if candidate is empty
        count skipped
        print lineFormatter.blank()
        continue
    result = process(candidate)
    print lineFormatter.line(result)
    record the result

print summaryFormatter.render(tally, path, errorLogPath)
write or delete error.log
```

The line formatter and the summary formatter return text. They do not read the file, call the guard, archive, or touch `error.log`. The command prints what they return. Padding and the summary sentences stay out of the loop.

`process` asks the library `UrlGuard`. A `url_syntax`, `url_policy`, or `url_rejected` rejection returns skipped and does not call `ArchiveService`. An accepted string is passed to `archive(new UrlPointer(candidate))`. `AlreadyStored` returns already stored and the fetcher was not called. `Written` returns archived. An `ArchiveException` whose code is one of those three guard codes is still skipped, in case the write reports it. Any other `ArchiveException` returns error and carries that failure code. The later rows still run. Any other throwable propagates. The run did not finish, so `error.log` is left as it was and the totals are not printed. That propagation rule is a call made here: the contract describes archive failures, not a broken store that throws past `ArchiveException`.

The scheme's letter case and the slashes are the candidate's. `UrlPointer` does not change them. The command does not rewrite them.

Once the file is accepted, standard output is:

```text
Importing: notes/urls.txt

SKIPPED         --
SKIPPED         not-a-url
ARCHIVED        https://example.com/a
ALREADY STORED  https://example.com/a
ERROR           https://example.com/b

Written 1
Already stored 1
Failed 1
Skipped 2

For error details see /home/ada/error.log
```

`Importing: ` is followed by the path argument as given. A blank line follows that heading, and another follows the last data row. The label is padded on the right with spaces to 16 characters, so the value starts at column 17. A blank field's value is `--`. `Written` counts `ARCHIVED`. `Already stored` counts `ALREADY STORED`. `Failed` counts `ERROR`. `Skipped` counts `SKIPPED`. When `Failed` is greater than 0 and `error.log` was written, a blank line and `For error details see `, then the absolute path of that file, follow the totals.

`error.log` is that name in the process working directory. The command writes it, not the tally. Each failure line is the trimmed URL, a tab, and the archive failure code, in file order. The file is replaced on a finished run that has at least one `ERROR`. A finished run with no `ERROR` deletes an existing `error.log` and does not print the details line. A finished run that cannot write it still prints the four totals, omits the details line, writes the cause to the error output, and exits 1. Symfony log channels are not used for this file. An early failure does not print `Importing:`.

A finished run with no `ERROR` exits 0, including an empty file and a file whose every row was skipped. Two identical URLs in one run fetch once. The first is `ARCHIVED`. The second is `ALREADY STORED`. The existing per-URL lock stays inside `ArchiveService`.

## Parts

One namespace, `Yumo\Eleanor\Import`. The names below are a call made here. The roles are not.

- The command. Symfony console. Reads the argument and `--owner`. Owns standard output, the error output, and `error.log`. Builds `Owner` and asks `ArchiveServiceFactory` for the service. Receives a factory of the candidate stream and walks that stream. It does not construct the line reader. Asks the formatters for text and prints it. Does not pad a column, guard, or archive.
- The candidate stream. The port the command depends on. It yields an empty candidate or a trimmed string.
- The line reader. Ticket 01's implementation of that port. It nests a raw-line stage and a trim stage. It is the only implementation in this ticket, so it sits beside the port. A second implementation is the reason to put the readers in a sub-namespace.
- The processor. Takes the guard and the owner's `ArchiveService`. Returns skipped, archived, already stored, or error.
- The line formatter. Turns one result into the 16-character label line. A blank candidate becomes `SKIPPED` and `--`. It does not count.
- The summary formatter. Turns the tally, the path argument, and the optional absolute path of `error.log` into the `Importing:` heading, the blank lines, the four totals, and the details line. It does not count and it does not write the file.
- The tally. Counts the four outcomes and keeps the error URLs and codes in file order. It does not print and it does not touch the filesystem.

The guard is the library `UrlGuard`. It is not `ArticleFetcher`. The command test therefore still rejects `not-a-url` when the fetcher is a double. The test's SSRF validator does not use the network. Syntax and policy follow the real guard rules.

## Left to the builder

- The PHP class names inside `Yumo\Eleanor\Import`, as long as the roles stay: command, candidate-stream port, line reader, processor, line formatter, summary formatter, tally. One line formatter covers the four labels. A class per label would split one padding rule.
- How an open file handle is passed into the line reader.
- How `UrlGuard` is constructed from the validator the application already uses for the orchestrator, without changing `ArchiveService`.
- The wording of an early cause, provided it is English and it is not one of the four prefixes.
- The test method names. The plan names them. The seam stays the one command run.

## Later tickets

Ticket 02 adds a CSV implementation of the candidate stream. It reads the comma-separated file, chooses the column, and does not yield the header. The values it yields are the same empty candidates and trimmed strings. The command loop, the processor, the tally, and the print lines stay. The line reader stays for a one-field file.

Ticket 03 refuses a binary before the stream yields. An early failure, as this ticket already does for an unreadable path.

Ticket 04 replaces the straight `foreach` with a cap. The default cap is 10. This ticket does not leave a cap of 1 in place. The candidate stream, the print lines, and the tally stay. The tally already keeps errors in file order, so a later pool does not have to invent that.
