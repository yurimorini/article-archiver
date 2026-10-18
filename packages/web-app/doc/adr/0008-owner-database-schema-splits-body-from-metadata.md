# The owner database splits the archive body from its metadata, and search is deferred

Status: accepted. The [SQLite store spec](../features/0002-server/specs/SqliteStore.md) adopts this.

The owner's SQLite file has three tables in version 1: `meta`, `archive`, and `archive_body`. `meta` is a key and value table. It holds the owner email and the creation time. `archive` holds one row per archive: an integer id, the unique `url`, the `title`, the nullable `excerpt` and `site_name`, and `created_at` as UTC ISO-8601 text. `archive_body` holds the purified `html` and shares the archive's id as its key, with a foreign key and `ON DELETE CASCADE`. The schema version lives in `PRAGMA user_version`. Numbered migrations each run once. Each connection sets `foreign_keys` and `busy_timeout`.

The archive row is small and the body can be large. A lookup by URL, which answers the second POST, reads the small row and never touches the body. A later refresh of a changed page, or a version history, changes only the body table.

The stored data has one source of truth: the URL, the metadata, and the purified HTML. Anything computed from that is derived and rebuildable: a plain-text column, a full-text index, chunks, or embeddings. Version 1 stores no derived data. Text search is a later migration in its own slice. It adds a plain-text column on `archive_body` and an FTS5 table keyed by the archive id, and it needs an HTML-to-text step that does not exist yet. Assets, tags, notes, and embeddings are also later tables that point at `archive.id`. None of them changes `archive`.

Search will be a separate port from the archive store. Reading an archive and searching archives are different jobs. One SQLite adapter may serve both.

One flat `archive` table with the HTML in the same row was the alternative. It is simpler, and SQLite keeps early columns readable without the overflow pages. It couples every later body change to the metadata row, and it leaves no clear place for versions.
