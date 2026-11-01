# Feature: Archive list

**Status:** Open

## Goal

A client lists one owner's archives and reads one archive, including the purified HTML. The list is `GET /api/archives`. The detail is `GET /api/archives/{id}`. `POST /api/archives` also returns the archive id.

The list document is the document a later search will reuse. This feature does not add search.

Terms live in the package [GLOSSARY.md](../../GLOSSARY.md). **Archive** is the stored copy. The page URL names the archive, and an integer id refers to the same archive. **Article** is the purified body. **URL pointer** is the name of the page. **Owner** is the person the archive belongs to. This feature adds no glossary term.

The backlog note `list.md` is adopted here and removed. [adr/0010](../../adr/0010-archive-list-is-a-page.md), [adr/0011](../../adr/0011-archive-id-is-public.md), and [adr/0012](../../adr/0012-archive-detail-is-read-by-id.md) record the decisions.

## Slices

| Order | Slice | Category | Done when | Status |
| --- | --- | --- | --- | --- |
| 1 | [Archive list](specs/archive-list.md) | HTTP | The list, the detail, and the id on POST match the spec | Tickets |

## Tickets

| | Ticket | Blocked by | Status |
| --- | --- | --- | --- |
| 01 | [YUM-8 Return the archive id from the archive command](https://linear.app/yurimorini/issue/YUM-8/return-the-archive-id-from-the-archive-command) | None | Done |
| 02 | [YUM-9 List a page of archives](https://linear.app/yurimorini/issue/YUM-9/list-a-page-of-archives) | YUM-8 | Done |
| 03 | [YUM-10 Read one archive](https://linear.app/yurimorini/issue/YUM-10/read-one-archive) | YUM-8 | Backlog |

## Design

| Ticket | Design |
| --- | --- |
| YUM-8 | [Archive id](design/ArchiveId.md) ([plan](plans/2026-10-31-archive-id.md)) |
| YUM-9 | [Archive list](design/ArchiveList.md) ([plan](plans/2026-11-01-archive-list.md)) |

## Current

[YUM-10 — Read one archive](https://linear.app/yurimorini/issue/YUM-10/read-one-archive). [YUM-8](https://linear.app/yurimorini/issue/YUM-8/return-the-archive-id-from-the-archive-command) and [YUM-9](https://linear.app/yurimorini/issue/YUM-9/list-a-page-of-archives) are Done. YUM-9 is on `list-route` at `ead930d`.
