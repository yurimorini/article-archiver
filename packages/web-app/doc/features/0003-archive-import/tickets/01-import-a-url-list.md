# 01: Import a URL list

**Feature:** [0003-archive-import](../feature.md)

**Contract:** [archive-import.md](../specs/archive-import.md)

**What to build:** An operator runs `archive:import` on a file with one URL per line and an owner email. Eleanor archives each accepted URL for that owner. The run prints `Importing: ` and the path. Each data row then prints one aligned result line, `SKIPPED`, `ARCHIVED`, `ALREADY STORED`, or `ERROR`, and the value in a second column. A blank row's value is `--`. The command then prints `Written`, `Already stored`, `Failed`, and `Skipped`. When `error.log` was written, the summary names its absolute path. A URL this owner already has is not fetched again. A failed write is also recorded in `error.log` and does not stop the rest of the file. Downloads run one at a time. A line that changes while a download runs is slice 2.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

**Type:** enhancement

- [ ] `archive:import <file> --owner <email>` archives each accepted URL in a one-field file for that owner, through the same write as `POST /api/archives`. The file path is the only argument. Standard input is not a source.
- [ ] A missing or empty `--owner` fails before the file is opened. The email is folded to lower case the way `Owner` already folds it. The configured HTTP owner is not used.
- [ ] A missing path, a directory, or an unreadable file fails before any archive: a cause on the error output, no totals, a pre-existing `error.log` left in place, and the same non-zero status as every other failure in this ticket.
- [ ] Leading and trailing spaces and tabs are removed. The scheme's letter case and the slashes stay as written. The URL after the prefix is that trimmed field.
- [ ] A blank line prints `SKIPPED` and `--`, and increments `skipped`. A cell the URL guard rejects prints `SKIPPED` and the field, increments `skipped`, and stays out of `error.log`.
- [ ] A write that stores a new archive prints `ARCHIVED` and the URL. An already stored URL prints `ALREADY STORED` and the URL, and is not fetched. A failed write prints `ERROR` and the URL. The value column starts at column 17. A second run of the same file fetches nothing.
- [ ] Two identical URLs in one run fetch once. One counts as written, the other as already stored.
- [ ] A failed write does not stop the later URLs. `error.log` in the working directory is replaced with one line per failure: the URL, a tab, and the archive failure code, in file order. The summary then says `For error details see` and the absolute path of that file. This file is not a Symfony log.
- [ ] A finished run with no write failure exits 0 and prints `Written N`, `Already stored N`, `Failed N`, and `Skipped N`. It deletes an existing `error.log`. An empty file prints `Importing: `, the path, and four zeros.
- [ ] If `error.log` cannot be written, the four totals still print, the `For error details see` line is absent, the cause goes to the error output, and the exit status is the failure status.
- [ ] Downloads run one at a time. The command does not accept `--parallel`.
- [ ] The sentences are English.
- [ ] Quality checks pass.
