# Archive id

Module design for YUM-8, the archive id on `POST /api/archives`. The behavior is [archive-list.md](../specs/archive-list.md) stories 47–51 and the archive-command revision that adds `id` to the success JSON. [adr/0011](../../../adr/0011-archive-id-is-public.md) is that id. This design adds no glossary term and no schema change.

## Intent

A client that archives a URL pointer receives the integer row id. The same id later names that archive on the list and on the detail. The URL still names the archive. The integer is the number SQLite assigns. It is not the signal that the row is new.

## Goals

`POST /api/archives` returns `id`, `url`, and `title`. A new row is 201. A URL this owner already has is 200 with the same id, and the page is not fetched. `id` in the JSON is a number.

## Non-goals

The list, the detail, search, and a refresh of a changed page. A new inbound type that copies `Archive` without an id. Using a null id to mean insert and a present id to mean update.

## Approaches

1. **`?int $id` on `Archive`.** The fetch builds an archive with `null`. `save` ignores that field, writes by URL, and returns a new `Archive` with the assigned id. `ArchiveSummary` carries `int $id` because a summary exists only after the row has a number. Chosen. One class keeps the fields. `null` means the number is not assigned yet.
2. **A second input type with no id.** Rejected. The fields would be a copy of `Archive`, and the null on `Archive` already marks “no number yet.”
3. **Null id means a new row, and a present id means an update.** Rejected. The lookup by URL already decides that the page is new. This POST does not refresh a changed page. `save` upserts by URL.

## Types

`Archive` gains `public ?int $id` as the last constructor argument. The URL stays first. The fetch passes `null`. There is no default: every caller passes the id. The class stays readonly, so the number is a new instance, not a write onto the fetched object.

`ArchiveSummary` gains `public int $id` as the last constructor argument. `fromArchive` copies it. A `null` id throws `\LogicException`. That call is a broken caller, not an archive failure. The service calls `fromArchive` only with the archive `save` returned.

`ArchiveResponse` gains `public int $id`. The constructor order is `id`, `url`, `title`, so the JSON properties follow the success body. `fromArchiveResult` copies `$result->summary->id`, `url`, and `title`. Excerpt, site name, outcome, and HTML stay out.

## Store

`ArchiveStore::save(Archive $archive): Archive`. The argument’s `id` is not read. The URL is the write key. The return is a new `Archive` with the same url, title, excerpt, site name, and HTML, and with the id of that row. A second save of the same URL keeps that id and replaces the other fields, as the upsert already does. A failure is still `ArchiveException` with `NotWritten`.

`find` selects `id` and returns an `Archive` with that integer. `findSummary` selects `id` and returns an `ArchiveSummary` with that integer. No new column and no schema version. SQLite already returns the id from `INSERT … RETURNING id`.

The test stand-ins implement the same signature. A stand-in assigns an integer, reuses it when the same URL is saved again, and puts that integer on `find` and `findSummary`.

## Data flow

1. `findSummary`. A hit returns `AlreadyStored` with that summary, including `id`. The fetcher is not called.
2. A miss builds `Archive` from the article with `id` `null`.
3. `save` returns the stored `Archive`. `Written` carries `ArchiveSummary::fromArchive` of that return value.
4. The reply prints `id`, `url`, and `title`.

An archive with `id` `null` is not equal to the archive `save` or `find` returns. Tests that compared the argument of `save` with `find` compare the returned archive with `find` instead.

## Errors

No new API code. A missing bearer stays 401 `unauthenticated`. `fromArchive` on a null id is a `\LogicException` and does not become the error document. `save` still throws `ArchiveException` with `NotWritten` and does not return an archive.

## Left to the builder

How a test stand-in picks the next integer, as long as the same URL keeps the same id. The assertions that move from the `save` argument to the returned archive. The `id` column on the existing `SELECT`s.

## Out of scope

`GET /api/archives` and `GET /api/archives/{id}`. Reading an archive by URL. A client-supplied id. Changing the bearer, the error document, or the owner file.

## List follow-up

[ArchiveList.md](ArchiveList.md) adds `?string $archived` on `Archive` and `string $archived` on `ArchiveSummary`, both as the last constructor argument. Null on `Archive` means the store has not returned the insert time. `fromArchive` throws `\LogicException` when `archived` is null, as it does for a null id. The value is the stored `created_at` text. `save`, `find`, and `findSummary` set it. A second save keeps that time. The POST JSON stays `id`, `url`, and `title`.
