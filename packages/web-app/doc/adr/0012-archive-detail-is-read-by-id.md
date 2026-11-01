# One archive is read by its id

Status: accepted 2026-10-31. Amended 2026-11-01. The archive list spec adopts this.

`GET /api/archives/{id}` returns that owner's archive. `{id}` is the integer the POST and the list return. A missing row is 404 `archive_not_found`, with the message "The archive does not exist." A path segment that is not a positive integer is 400 `invalid_query`, with the message "A parameter has an invalid value." `HEAD` returns the GET status and headers with an empty body. The URL stays a field of the body. The client does not send the URL to read the archive.

The body is `url`, `title`, `excerpt`, `siteName`, `id`, `archived`, and `html`. Those first six fields match one list element. `html` is the purified article HTML and is always a string. `excerpt` and `siteName` are null when the page has none.

Reading by URL was the alternative. The URL has reserved characters, and Wallabag, linkding, and Raindrop already split "is this URL saved?" from "read this item." `POST /api/archives` answers the first. This route answers the second. The notes are in [bookmark-single-item.md](../research/bookmark-single-item.md).
