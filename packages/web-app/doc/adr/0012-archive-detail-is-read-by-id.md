# One archive is read by its id

Status: accepted 2026-10-31. Amended 2026-11-01. Amended again 2026-11-01, before the detail shipped. The archive list spec adopts this.

`GET /api/archives/{id}` reads that owner's archive by the integer the POST and the list return. The client does not send the URL. A missing row, including a missing database file, is 404 `archive_not_found`, with the message "The archive does not exist." A path segment that is not a positive integer is 400 `invalid_query`, with the message "A parameter has an invalid value." `HEAD` returns the GET status and headers with an empty body. Every failure stays `application/json`.

The same id has three representations. The bare GET is `application/json` and one list element: `url`, `title`, `excerpt`, `siteName`, `id`, `archived`. It has no `html`. `?includeContent=true` adds `html`, the purified article HTML, as a JSON string. Any other `includeContent` value is 400 `invalid_query`. `GET /api/archives/{id}.html` is `text/html` and the stored HTML alone, with no template. The metadata read does not touch `archive_body`. The other two read that column.

One JSON body that always included `html` was the alternative. It is one call, and putting HTML in a JSON string is safe. It makes a metadata read pay for the body, and it leaves no raw HTML response for a later page template. A file beside the SQLite database was the other alternative. The HTML already lives in `archive_body`.

Reading by URL stays rejected. The URL has reserved characters, and Wallabag, linkding, and Raindrop already split "is this URL saved?" from "read this item." `POST /api/archives` answers the first. These routes answer the second. The notes are in [bookmark-single-item.md](../research/bookmark-single-item.md).
