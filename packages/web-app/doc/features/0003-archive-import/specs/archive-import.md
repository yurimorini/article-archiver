**Status:** ready-for-agent

## Problem Statement

An operator who has a file of links, including a Raindrop CSV or TXT export, cannot ask Eleanor to keep an archive of those pages. `POST /api/archives` stores one URL pointer per call. There is no command that reads a file, skips what is not a URL, and archives the rest for an owner the operator names.

## Solution

`archive:import` takes one file and a required owner email. It reads the file as one field per line, or as comma-separated columns, and archives each accepted URL pointer with the same write as `POST /api/archives`. Each data row prints one result line: `SKIPPED`, `ARCHIVED`, `ALREADY STORED`, or `ERROR`, then the URL. At the end the command prints four totals. An `ERROR` also goes to `error.log` in the working directory. A URL this owner already has is not fetched again. Columns other than the URL are not stored. A line that changes while a download runs is slice 2.

## User Stories

1. As an operator of Eleanor, I want `archive:import <file> --owner <email>` to archive the accepted URLs in that file, so that one command stores the pages.
2. As an operator of Eleanor, I want the file path to be the only argument, so that a URL on the command line is not a second source.
3. As an operator of Eleanor, I want standard input to be ignored, so that a pipe is not a source.
4. As an operator of Eleanor, I want `--owner` to be required, so that the command cannot guess the owner.
5. As an operator of Eleanor, I want a missing `--owner` to fail before the file is opened, so that no archive is written.
6. As an operator of Eleanor, I want an empty `--owner` to fail before the file is opened, so that an empty email is not an owner.
7. As an operator of Eleanor, I want the email folded to lower case the way `Owner` already folds it, so that `Owner@Example.com` and `owner@example.com` are the same owner.
8. As an operator of Eleanor, I want the archives stored for that email, so that a different owner does not receive them.
9. As an operator of Eleanor, I want the configured HTTP owner left unused, so that the email on the command is the owner of this run.
10. As an operator of Eleanor, I want a missing path to fail before any archive, so that a typo stores nothing.
11. As an operator of Eleanor, I want a directory path to fail before any archive, so that a directory is not read as a list of URLs.
12. As an operator of Eleanor, I want an unreadable file to fail before any archive, so that a permissions failure stores nothing.
13. As an operator of Eleanor, I want those early failures to print a cause and no totals, so that a run that did not start does not look like a finished import.
14. As an operator of Eleanor, I want those early failures to leave an existing `error.log` untouched, so that a typo does not erase the previous run's failures.
15. As an operator of Eleanor, I want those early failures to exit non-zero, so that a script can see the run did not finish.
16. As an operator of Eleanor, I want a file whose media type does not start with `text/` to be refused as binary, so that an image or a gzip is not parsed as links.
17. As an operator of Eleanor, I want `text/plain` and `text/csv` to be read, so that a URL list and a CSV pass the binary check.
18. As an operator of Eleanor, I want `text/html` to be read as text, so that the binary check is the media type and not a list of formats.
19. As an operator of Eleanor, I want that classification to use `ext-fileinfo` when it is present, so that libmagic makes the decision.
20. As an operator of Eleanor, I want a missing `ext-fileinfo`, or a file `finfo` cannot classify, to treat a NUL byte in the first 8192 bytes as binary, so that the command still refuses a binary.
21. As an operator of Eleanor, I want a file with no NUL in that window to be read as text when libmagic is unavailable, so that a URL list still imports.
22. As an operator of Eleanor, I want a binary refusal to follow the same early failure as an unreadable path, so that nothing is archived and `error.log` stays as it was.
23. As an operator of Eleanor, I want a leading UTF-8 BOM ignored, so that the first cell can still be `url`.
24. As an operator of Eleanor, I want lines split on LF or CRLF, so that the CR is not part of the last field.
25. As an operator of Eleanor, I want every line read with comma as the separator, so that a CSV column is a field.
26. As an operator of Eleanor, I want quotes to be optional, so that a bare URL is one field.
27. As an operator of Eleanor, I want a field that starts with `"` to keep an internal comma, so that `1,"Hello, world",https://example.com/a` still has the URL in the third field.
28. As an operator of Eleanor, I want `""` inside a quoted field to be one quote character, so that a quoted quote does not break the columns.
29. As an operator of Eleanor, I want a file whose every line has one field to be a URL list, so that a Raindrop TXT export is one URL per line.
30. As an operator of Eleanor, I want one line with two or more fields to make the whole file comma-separated, so that the column rules apply to every line.
31. As an operator of Eleanor, I want an unquoted comma inside a URL to make that file comma-separated, so that the comma is a separator.
32. As an operator of Eleanor, I want the whole file read before the first archive, so that a late multi-field line still selects the column for the earlier lines.
33. As an operator of Eleanor, I want `--field` and `--index` together to fail before any archive, so that the column is chosen one way.
34. As an operator of Eleanor, I want `--field` or `--index` on a one-field file to fail before any archive, so that a column option is not applied to a URL list.
35. As an operator of Eleanor, I want a one-field file with neither option to use each line as the URL, so that the list needs no flag.
36. As an operator of Eleanor, I want a comma-separated file with neither option to use the column whose header cell is `url`, so that a Raindrop CSV needs no flag.
37. As an operator of Eleanor, I want that match to be exact on the trimmed cell, so that `url` matches and `URL` does not.
38. As an operator of Eleanor, I want the first matching cell in the first row to win, so that a repeated name has one column.
39. As an operator of Eleanor, I want that first row to be the header, so that the word `url` is not archived.
40. As an operator of Eleanor, I want a comma-separated file with no `url` cell and neither option to fail before any archive, so that the command does not guess another column.
41. As an operator of Eleanor, I want `--field NAME` to select that header cell the same way, so that another export can name its URL column.
42. As an operator of Eleanor, I want `--field` with no matching cell to fail before any archive, so that a wrong name stores nothing.
43. As an operator of Eleanor, I want `--index` to count from 0, so that `0` is the first column.
44. As an operator of Eleanor, I want `--index` to treat the first row as data, so that a headerless file does not lose its first URL.
45. As an operator of Eleanor, I want `--index 4` on a headerless row `id,title,note,excerpt,https://example.com/a` to archive that URL, so that the Raindrop URL position is the fifth field.
46. As an operator of Eleanor, I want a negative `--index`, or a value that is not an integer, to fail before any archive, so that `false`, `1.5`, and `-1` are not columns.
47. As an operator of Eleanor, I want `00` accepted as index 0, so that a numeric string is the integer value.
48. As an operator of Eleanor, I want each field to lose leading and trailing spaces and tabs before use, so that padding around a URL or a header is not part of the value.
49. As an operator of Eleanor, I want the scheme's letter case and the slashes to stay as written, so that the stored URL pointer is the trimmed field.
50. As an operator of Eleanor, I want a blank line skipped, so that an empty row is not an archive.
51. As an operator of Eleanor, I want a row that lacks the chosen column skipped, so that a short CSV row is not a failure.
52. As an operator of Eleanor, I want the header row excluded from the skipped total, so that the word `url` does not count as a skipped link.
53. As an operator of Eleanor, I want a cell the URL guard rejects for syntax, policy, or a non-public address to be skipped, so that only an accepted URL is archived.
54. As an operator of Eleanor, I want a skipped cell to print `SKIPPED` in the label column and the trimmed field in the value column, and to stay out of `error.log`, so that a skip is not a write failure.
55. As an operator of Eleanor, I want an accepted URL archived by the same write as `POST /api/archives`, so that the stored archive is the one that route would store.
56. As an operator of Eleanor, I want the title, note, tags, folder, and the other cells left in the file, so that this command does not create a bookmark.
57. As an operator of Eleanor, I want a URL that already has an archive for this owner not to be fetched again, so that a second import does not refresh the page.
58. As an operator of Eleanor, I want an already stored URL to print `ALREADY STORED` and that URL, so that a repeat import is visible and is not a new archive.
59. As an operator of Eleanor, I want a new archive to print `ARCHIVED` and that URL after the write returns, so that this slice shows the result and not a line that changes while the download runs.
60. As an operator of Eleanor, I want a failed write to print `ERROR` and that URL, so that the failure is on standard output as well as in `error.log`.
61. As an operator of Eleanor, I want the value column to be the trimmed field, so that the line and the stored pointer are the same string. A blank field prints `--` in the value column.
62. As an operator of Eleanor, I want at most 10 downloads in flight when `--parallel` is omitted, so that the default cap is 10 across every host.
63. As an operator of Eleanor, I want `--parallel 1` and `--parallel false` to run one download at a time, so that either spelling is serial.
64. As an operator of Eleanor, I want `--parallel` from 2 to 10 to keep that many downloads in flight, so that the operator can lower the cap.
65. As an operator of Eleanor, I want `--parallel` above 10, `0`, a negative, or any other word to fail before any archive, so that `FALSE` and `11` do not start a run.
66. As an operator of Eleanor, I want a failed download not to cancel the URLs still queued, so that one failure does not drop the rest of the file.
67. As an operator of Eleanor, I want two identical URLs in one run to fetch once, so that the archive lock still covers lookup, fetch, and save.
68. As an operator of Eleanor, I want the second of those URLs counted as already stored, so that one write and one hit are visible in the totals.
69. As an operator of Eleanor, I want starts to follow file order, so that the next URL starts when a slot is free.
70. As an operator of Eleanor, I want the totals after the rows, as `Written N`, `Already stored N`, `Failed N`, and `Skipped N`, so that the summary is counts and not a second list of URLs.
71. As an operator of Eleanor, I want `written` to count archives this run stored, so that a new page is distinct from one already stored.
72. As an operator of Eleanor, I want `already stored` to count accepted URLs that were not fetched, so that a repeat import is visible.
73. As an operator of Eleanor, I want `failed` to count archive writes that failed after the guard accepted the URL, so that a transport failure is not a skip.
74. As an operator of Eleanor, I want `skipped` to count blank lines, short rows, and cells the guard rejected, so that the header is the only row left out of every total.
75. As an operator of Eleanor, I want a run with at least one failure to rewrite `error.log` in the working directory, so that the file is this run's failures.
76. As an operator of Eleanor, I want each failure line to be the URL, a tab, and the archive failure code, so that the code is the same string the HTTP API uses.
77. As an operator of Eleanor, I want those lines in file order, so that parallel completion does not reorder the log.
78. As an operator of Eleanor, I want the summary to end with `For error details see` and the absolute path of `error.log` when that file was written, so that the log is visible without opening the working directory.
79. As an operator of Eleanor, I want a finished run with no failures to delete an existing `error.log`, so that the file describes this run.
80. As an operator of Eleanor, I want a finished run that could not write `error.log` to still print the totals, omit the `error.log` line, and exit non-zero, so that the counts survive a log failure.
81. As an operator of Eleanor, I want the cause of that log failure on the error output, so that the totals stay a clean summary.
82. As an operator of Eleanor, I want an empty file to finish with all four totals at 0 and exit 0, so that nothing to do is success.
83. As an operator of Eleanor, I want a file whose every data row was skipped to exit 0, so that skips alone are not a failed run.
84. As an operator of Eleanor, I want any write failure to exit with one non-zero status, so that usage errors, binary refusals, and write failures share that status.
85. As an operator of Eleanor, I want a finished run with no write failure to exit 0, so that skips and already stored URLs are still success.
86. As an operator of Eleanor, I want the command's sentences in English, so that they match the API messages.
87. As an operator of Eleanor, I want the early cause on the error output, so that it is not mixed into the URL lines.
88. As a developer of Eleanor, I want this command to call the existing archive write in process, so that it does not POST to itself and does not read the bearer token.
89. As a developer of Eleanor, I want `error.log` kept out of the Symfony log channels, so that this file is not `var/log`.
90. As a developer of Eleanor, I want a line that shows the URL while the download runs, and the in-place `[n/total]` loader, left to the next slice, so that this slice prints one prefixed result line per data row and then the totals.

## Implementation Decisions

- The command name is `archive:import`. The file path is the only argument. `--owner` takes the email and is required. `--field` takes a header name. `--index` takes an integer. `--parallel` takes either the word `false` or an integer. Omitted `--parallel` means 10.
- The email is stored the way `Owner` already stores it: lower case, and not otherwise trimmed. An empty value fails before the file is opened. The HTTP parameter that names the configured owner is not read.
- The command builds the owner's archive service the way a caller outside HTTP already can. It passes a URL pointer of the trimmed field into that write. It does not rewrite scheme case or slashes. It does not send HTTP to Eleanor.
- The file is classified before any archive. A path that is missing, a directory, or unreadable is an early failure. `ext-fileinfo` supplies the media type. A type that does not start with `text/` is an early failure. If the extension is missing or `finfo` returns no type, the first 8192 bytes are scanned: a NUL byte is an early failure, and the absence of a NUL means text.
- The file is then read to the end. A leading UTF-8 BOM is discarded. Lines split on LF or CRLF. Each line is parsed as comma-separated fields. A field may be unquoted. A field that begins with `"` runs until the closing quote, a comma inside it is not a separator, and `""` is one quote. If every line yields one field, the file is a URL list. If any line yields more than one field, the whole file uses the column rules.
- On a URL list, `--field` and `--index` are early failures. With neither, each trimmed line is a candidate. On a comma-separated file, both options together are an early failure. `--field` compares the trimmed cells of the first row and takes the first cell equal to the name; that row is the header. `--index` parses a base-10 integer of 0 or more, counts from 0, and treats the first row as data. `00` is 0. A sign, a fraction, or any other word is an early failure. With neither option, the header name `url` is required. A missing header name, or a missing `url` when no option was given, is an early failure.
- Every field loses leading and trailing ASCII spaces and tabs before the header match, the guard, and the archive. The URL guard is the one `POST /api/archives` already uses. `url_syntax`, `url_policy`, and `url_rejected` skip the cell. A blank field and a missing column skip the row. The header row is not a candidate.
- Accepted URL pointers are started in file order, with at most N in flight. The default N is 10. `--parallel false` and `--parallel 1` set N to 1. An integer from 2 to 10 sets N. Any other value is an early failure. A failure does not cancel the queue. The existing per-owner, per-URL lock still wraps lookup, fetch, and save, so two identical URLs in one run fetch once.
- Once the file is accepted, standard output begins with `Importing: `, then the path argument as given. A blank line follows. Each data row is one line after that row's result is known. The label is `SKIPPED`, `ARCHIVED`, `ALREADY STORED`, or `ERROR`, padded on the right with spaces to 16 characters. The value starts at column 17. A blank field's value is `--`. Any other value is the trimmed field. `ARCHIVED` is a write that stored a new archive. `ALREADY STORED` is an accepted URL that was not fetched. `ERROR` is a write that failed after the guard accepted the URL. `SKIPPED` is a blank field or a cell the guard rejected. A blank line follows the rows. The totals are `Written N`, `Already stored N`, `Failed N`, and `Skipped N`. `Written` counts `ARCHIVED`. `Already stored` counts `ALREADY STORED`. `Failed` counts `ERROR`. `Skipped` counts `SKIPPED`, and does not count the header row.

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

- When `Failed` is greater than 0 and `error.log` was written, a blank line and `For error details see `, then the absolute path of that file, follow the totals. The file is `error.log` in the process working directory. It is replaced on each such run. Each line is the trimmed URL, a tab, and the archive failure code, in file order. Symfony log channels are not written by this file. When `Failed` is 0, an existing `error.log` in that directory is removed. When the file cannot be written, the four totals still print, the details line is absent, the cause goes to the error output, and the exit status is the failure status. An early failure does not print `Importing:`.
- An early failure writes the cause to the error output, prints no totals, does not create or delete `error.log`, archives nothing, and exits with the failure status. Every failure kind shares that one non-zero status. A finished run with no write failure exits 0, including an empty file and a file whose every data row was skipped. The sentences are English.

## Testing Decisions

- A good test observes the command from the outside. It does not assert a private call order, a parser class name, or the text of an early cause beyond the rules below.
- The seam is one console run of `archive:import`. The test sets the working directory, passes the file and the options, and reads the exit status, standard output, the error output's presence, `error.log`, and the archives stored for that owner. The archive write is the existing one. An `ArticleFetcher` double replaces the network and records how many fetches overlap and which URL pointers were fetched. The URL guard is the real guard. `ArchiveService` tests and the HTTP route tests stay the proof of a single archive write. This spec does not add a second copy of them.
- On that seam, a one-field file prints `Importing: ` and the path, then `ARCHIVED` in the label column and the URL in the value column after the write stores it. A failed fetch prints `ERROR` and the URL, and puts that URL in `error.log`. A cell the guard rejects prints `SKIPPED` and the field, and stays out of `error.log`. A blank line prints `SKIPPED` and `--`. A second run of the same file prints `ALREADY STORED` for each URL, totals `Already stored` for each, fetches nothing, and exits 0. The value column starts at column 17. A Raindrop-shaped header `id,title,note,excerpt,url` with neither `--field` nor `--index` archives the `url` column and does not count the header as skipped. `--field` selects another header. `--index 0` takes the first cell and archives a URL that sits on the first row. `--field` together with `--index`, either option on a one-field file, a missing `url` header, a negative index, `--parallel 11`, `--parallel FALSE`, a missing owner, a directory, and a binary fixture each exit with the same non-zero status, print no totals, and leave a pre-seeded `error.log` in place.
- A quoted comma keeps the URL in its column. A UTF-8 BOM still matches `url`. A CRLF file does not put a CR in the URL. Leading and trailing spaces are not stored. A cell that fails the guard prints `SKIPPED` and increments `skipped`, and is absent from `error.log`. An empty file prints `Importing: `, the path, four zeros, and exits 0.
- The double shows at most 10 overlapping fetches by default, at most 2 with `--parallel 2`, and no overlap with `--parallel 1` or `--parallel false`. Two copies of one URL fetch once. A thrown archive failure does not stop the later URLs. `error.log` then contains the failed URL, a tab, and that failure code, in file order, and standard output ends with `For error details see` and the absolute path of that file. A finished run with no failure removes a pre-seeded `error.log`. The totals use `Written`, `Already stored`, `Failed`, and `Skipped`.
- A media type that does not start with `text/` is refused. The NUL fallback is covered by classifying a fixture without `finfo`: a NUL in the first 8192 bytes is refused, and a text fixture is read. The test does not assert libmagic's name for a particular CSV.

## Out of Scope

- A line that updates while a download runs: a `[n/total]` counter, a textual loader, the URL being fetched, and an ok or error mark on one line at the bottom of the terminal. That is slice 2 of this feature. This slice prints one prefixed result line per data row.
- A bookmark, a tag, a note, a folder, or any cell other than the chosen URL.
- Standard input, URL arguments, and the configured HTTP owner as a default.
- Refreshing an archive that this owner already has.
- A separate Symfony log line for each import failure.
- More than one non-zero exit status.
- A new glossary term.

## Further Notes

- [feature.md](../feature.md) is the index. Slice 2 stays in that file until it has a spec.
- The module design is the next step after this contract is accepted. It names the command's types. The plan names the test methods.
- `text/html` is read as lines. A Raindrop HTML export is not parsed as bookmarks. Lines that are not URLs are skipped.
