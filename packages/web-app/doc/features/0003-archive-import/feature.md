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

## Tickets

| | Ticket | Blocked by | Status |
| --- | --- | --- | --- |
| 01 | [Import a URL list](tickets/01-import-a-url-list.md) | None | ready-for-agent |
| 02 | [Read a comma-separated URL column](tickets/02-read-a-comma-separated-url-column.md) | 01 | ready-for-agent |
| 03 | [Refuse a binary file](tickets/03-refuse-a-binary-file.md) | 01 | ready-for-agent |
| 04 | [Cap in-flight downloads](tickets/04-cap-in-flight-downloads.md) | 01 | ready-for-agent |

## Current

Slice 1, ticket [01 — Import a URL list](tickets/01-import-a-url-list.md). The module design is [UrlListImport.md](design/UrlListImport.md). The plan is [2026-10-24-url-list-import.md](plans/2026-10-24-url-list-import.md). Tickets 02, 03, and 04 wait on it.
