# Bookmark

A reflection recorded on 2026-10-23. No feature owns it. The glossary does not define Bookmark. Archive and URL pointer both avoid that word. Adopting the term means defining it there first.

A bookmark is the mark an owner puts on a URL. It is not the stored copy of the page, and it is not the URL pointer. One bookmark per owner and URL. A bookmark may exist with no archive. An archive may exist with no bookmark. The join is `(owner, url)`. `https://example.com/a` and `https://example.com/a/` stay two bookmarks, as they stay two URL pointers.

## Entity

```text
Owner
  email

UrlPointer                 value, already exists
  url                      same trim of NUL bytes at the ends; slash and letter case stay

Bookmark                   the mark, one per (owner, url)
  id
  owner
  url                      the URL pointer string
  title                    the owner's title; empty when they set none
  note                     the owner's text; empty when they set none
  unread                   bool
  createdAt
  updatedAt

Tag
  id
  owner
  name                     unique per owner

BookmarkTag
  bookmarkId
  tagId

Archive                    already exists, independent
  owner + url
  title, excerpt, siteName, html

Article                    already exists, inside the archive, no URL
```

The bookmark points at the URL. It does not hold `html`, `excerpt`, or `siteName`.

Left out of the save: a resolved URL after a redirect (another page), highlights (a reading of the copy), favicon and reading time (presentation cache), visits (history), folders (a place with order, wanted only when the UI is a tree). A tag is a label. A folder is a place.

The stable id is not the URL. The unique constraint is `(owner, url)`. Pinboard uses the URL as the key. An id leaves a later URL edit as its own operation.

## Sources

Read on 2026-10-23.

Raindrop is hosted. The free plan is unlimited bookmarks, collections, and devices, with collaboration. The permanent copy is Pro, and it is a separate object. There is no unread flag and no archived flag on the mark.

- [Raindrop fields](https://developer.raindrop.io/v1/raindrops): `important` (favorite), `broken` (the original link does not answer), `type` (content kind), `reminder` (a date), `collection`, `cache`.
- [Single raindrop](https://developer.raindrop.io/v1/raindrops/single): delete moves the raindrop to Trash; delete from Trash is permanent. `GET /raindrop/{id}/cache` is the permanent copy.
- [Collections](https://developer.raindrop.io/v1/collections): system collections Unsorted (`-1`) and Trash (`-99`).
- [Filters](https://developer.raindrop.io/v1/filters): counts for broken, duplicates, important, untagged, tags, types.
- [Pro](https://raindrop.io/pro): full-text search, permanent copies, and backup are Pro. The page does not print the price.

Open projects still pushed in October 2026:

- [linkding](https://github.com/sissbruecker/linkding) (MIT, release 1.47.0 on 2026-09-13). [`bookmarks/models.py`](https://github.com/sissbruecker/linkding/blob/master/bookmarks/models.py): `unread` and `is_archived` are independent booleans on the bookmark. `is_archived` hides the mark from the active list. The snapshot is `BookmarkAsset`, with `status` `pending`, `complete`, or `failure`. `web_archive_snapshot_url` is a Wayback URL. `BookmarkBundle` is a saved search.
- [Karakeep](https://github.com/karakeep-app/karakeep) (AGPL-3.0, release 0.34.1 on 2026-10-22). [`packages/db/schema.ts`](https://github.com/karakeep-app/karakeep/blob/main/packages/db/schema.ts): `bookmarks.archived` is the reading flag, beside `favourited`. The copy is an `assets` row (`linkFullPageArchive`, `linkPdf`, `linkScreenshot`, and others). `bookmarkLinks` also carries `htmlContent`, `crawlStatus`, and `readerViewStatus` on the link row. `taggingStatus`, `summarizationStatus`, and `embeddingStatus` are job states. Lists are `manual` or `smart`.
- [Linkwarden](https://github.com/linkwarden/linkwarden) (AGPL-3.0, release 2.16.3 on 2026-09-09). [`packages/prisma/schema.prisma`](https://github.com/linkwarden/linkwarden/blob/main/packages/prisma/schema.prisma): every link belongs to a collection. The pin is a relation to the user. The copy is columns on the same row (`image`, `pdf`, `readable`, `monolith`). No unread flag in the model as read.
- [LinkAce](https://github.com/Kovah/LinkAce) (GPL-3.0). [API](https://api-docs.linkace.org/v1): no unread. `status` is the link check (`broken_only` means status is not Ok). `deleted_at` is trash. `is_private` is on the link, the list, the tag, and the note. The same page mixes v1 `is_private` and v2 `visibility`. Notes are child rows.
- [wallabag](https://github.com/wallabag/wallabag) (MIT, pushed 2026-10-23) and [Shiori](https://github.com/go-shiori/shiori) (MIT, pushed 2026-10-20) store the extracted article with the mark. They are read-later: the "read" state lives on the entry that holds the body.
- [Shaarli](https://github.com/shaarli/Shaarli) (pushed 2026-10-21): URL, title, description, tags, private. No reading state and no copy. The GitHub license is SPDX `NOASSERTION`.

[Mozilla, 2025-05-22](https://blog.mozilla.org/en/mozilla/building-whats-next/): Pocket shut down on 2025-07-08. That post sets export to 2025-10-08. A later Firefox support note sets export to 2025-11-12. The canonical support article did not open on this reading.

## Roles

Four roles showed up under the word "state". They are open. This note does not pick one shape.

- **Intention of the mark.** At most one: to read, or favorite. linkding uses `unread`. Raindrop uses `important` and has no unread flag.
- **Place.** A collection, a list, or the trash is another object. Raindrop uses system collections (`-1`, `-99`). Linkwarden requires a collection. Karakeep uses lists. LinkAce uses `deleted_at`.
- **Link health.** A check that the URL still answers. Raindrop uses `broken`. LinkAce uses `status`.
- **Outcome of the copy.** The status of a job on the Archive. Raindrop uses `cache.status` (`ready`, `retry`, `failed`, `invalid-origin`, `invalid-timeout`, `invalid-size`). linkding uses `BookmarkAsset.status`.

`is_archived` in linkding and `bookmarks.archived` in Karakeep mean "hide from the inbox". In Eleanor that name is already the copy of the page.
