# Feature: Archive import

**Status:** Open

## Goal

An operator archives every accepted URL in one file for one owner. The command is `archive:import`. The file is a list of URL pointers: one field per line, or a comma-separated file whose URL column is `url` by default. Each accepted URL is written with the same archive write as `POST /api/archives`. Title, note, tags, folder, and the other columns stay in the file.

The owner is the email passed to the command. The configured owner of the HTTP route is not the default.

Terms live in the package [GLOSSARY.md](../../GLOSSARY.md). **Archive** is the stored copy. **URL pointer** is the name of the page. **Owner** is the person the archive belongs to, identified by an email address. This feature adds no glossary term. Bookmark stays undefined.

## Slices

| Order | Slice | Category | Done when | Status |
| --- | --- | --- | --- | --- |
| 1 | [Archive import](specs/archive-import.md) | Import | `archive:import` reads one file, archives the accepted URLs for the given owner, prints one prefixed result line per data row, prints the totals, and writes `error.log` for failures | Tickets |
| 2 | Import progress line | Import | One terminal line stays at the bottom and updates in place while a download runs: a `[n/total]` counter, a textual loader, and the URL. When that download finishes, the loader becomes an ok or error mark. The totals and `error.log` stay as slice 1 defines them | Later |

Slice 2 belongs to this feature. It is not a package backlog note.

Revised 2026-10-24: slice 1 prints `Importing: ` and the path, then one aligned result line per data row. The label column is 16 characters. A blank value is `--`. The totals are `Written`, `Already stored`, `Failed`, and `Skipped`. When `error.log` was written, the summary gives its absolute path. The line that updates while a download runs stays in slice 2.

Revised 2026-10-24: the command and a console writer move to `Yumo\Eleanor\Commands\Import`. The stream, the processor, the formatters, and the tally stay in `Yumo\Eleanor\Import`. The writer is the only type that calls Symfony `write` or `writeln`. The observable run is unchanged.

## Tickets

| | Ticket | Blocked by | Status |
| --- | --- | --- | --- |
| 01 | [Import a URL list](tickets/01-import-a-url-list.md) | None | Done |
| 02 | [YUM-5 Read a comma-separated URL column](https://linear.app/yurimorini/issue/YUM-5/read-a-comma-separated-url-column) | 01, done | Backlog |
| 03 | [YUM-6 Refuse a binary file](https://linear.app/yurimorini/issue/YUM-6/refuse-a-binary-file) | 01, done | Backlog |
| 04 | [YUM-7 Cap in-flight downloads](https://linear.app/yurimorini/issue/YUM-7/cap-in-flight-downloads) | 01, done | Backlog |

Tickets 02–04 are Linear issues. Each has labels `Feature` and `ready-for-agent`. Ticket 01 stays in this folder because it is done. The files under `tickets/` for 02–04 are the old copies.

## Current

Slice 1, ticket [YUM-5 — Read a comma-separated URL column](https://linear.app/yurimorini/issue/YUM-5/read-a-comma-separated-url-column). Ticket 01 is done at `1fa4f39`. The module design is [UrlListImport.md](design/UrlListImport.md). The plan is [2026-10-24-url-list-import.md](plans/2026-10-24-url-list-import.md). [YUM-6](https://linear.app/yurimorini/issue/YUM-6/refuse-a-binary-file) and [YUM-7](https://linear.app/yurimorini/issue/YUM-7/cap-in-flight-downloads) are unblocked too.
