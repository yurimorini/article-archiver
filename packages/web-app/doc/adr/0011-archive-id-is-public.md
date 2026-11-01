# The archive id is public on the archive command and the list

Status: accepted 2026-10-31. The archive list spec adopts this.

`POST /api/archives` returns `id`, `url`, and `title`. A new archive is 201 and an archive already stored is 200, and both return the same `id` for that row. Each element of `GET /api/archives` includes that same `id`. The id is the integer row id. It stays the same when the title or the HTML changes. A new database file, or a new row after the old row is gone, assigns another id. The URL still names the archive.

Keeping the id inside the database was the alternative. The list would then have no field in common with a POST that returns only `url` and `title`. A UUID in place of the integer was the other alternative. One owner file does not need a client-generated id, and the child rows already point at the integer.
