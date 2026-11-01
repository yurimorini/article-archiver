# The archive list is a page, and sort names are not column names

Status: accepted 2026-10-31. Amended 2026-11-01. The archive list spec adopts this.

`GET /api/archives` returns one page of archives. The client sends `page`, `size`, `sort`, and `dir`. The response body is `items`, `total`, `page`, `size`, `sort`, `dir`, and `next`. `total` is the number of archives, not the number of pages. `size` repeats the requested size on the last page. `next` is the URL of the following page, or null when the page is the last. An empty list is 200 with `items` `[]`, `total` 0, and `next` null. A new archive between two requests can repeat an archive on the next page or skip one. The client chose page numbers over a continuation token for that reason: the client can open page 5 without reading pages 1–4.

`sort` and `dir` are public tokens. An allow-list maps each token to storage. The query string does not carry column names. The only `sort` token today is `archived`, and the server translates `archived` to the archive row's insert time. `dir` is `asc` or `desc`. A `sort` token outside the list is 400. The client may send the parameters in any order. `next` always writes them as `sort`, `dir`, `page`, `size`.

A request that omits a parameter uses `sort=archived`, `dir=desc`, `page=1`, and `size=10`. The response repeats those applied values. `next` for that request is `/api/archives?sort=archived&dir=desc&page=2&size=10`.

`size` has a configured maximum. The starting maximum is 100. A `size` above that maximum is 400 `invalid_query`. The server does not shrink the page to the maximum.

A present parameter outside its allowed values is also 400 `invalid_query`. That covers a `sort` token outside the list, a `dir` other than `asc` or `desc`, a `page` below 1, a `size` below 1, and a `page` or a `size` that is not an integer. A page past the last page is 200. With 98 archives and `size=10`, `page=11` returns `items` `[]`, `total` 98, and `next` null. The fixed message for `invalid_query` is "A parameter has an invalid value."

Rows are ordered by the insert time and then by the row id, both in the requested direction. The id is not a public sort token. `HEAD /api/archives` returns the GET status and headers with an empty body.

Each element of `items` is `url`, `title`, `excerpt`, `siteName`, `id`, and `archived`, in that order. `id` is the integer row id. `excerpt` and `siteName` are null when the page has none. `siteName` is the stored site name. The column `site_name` stays in the store. `archived` is the row insert time in UTC, the same instant as `sort=archived`. The element has no HTML.

A continuation token, either the last archive URL or an opaque string, was the alternative. The token stays on one position when a new archive is stored, and the client cannot open a later page directly. `sort=created_at` was the other rejected name: the token would publish the column.
