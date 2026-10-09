# Domain storage

This file records a research session. It is not a final decision. A later session can replace it. A spec adopts it when implementation starts.

## Two stores

The domain port is how an archive is read and written. Every call carries an owner. The first adapter behind that port is one SQLite file for that owner, so a copy of the file is that owner's URL list and archives. A later adapter may keep every owner in one database and still answer the same port. Boundedness, one file per owner, is a property of the first adapter.

Application state stays out of that file. Request ids, rate limits, and an idempotency record, if one is added, share one store for the whole application. That store has no owner.

## The port

The service receives the owner and the URL string. The port reads an archive by that pair and writes an archive this owner does not have yet. Another owner's copy is not a hit. [0004](../../../adr/0004-archive-lookup-is-per-owner.md) records why.

A later adapter may keep one article body and an ownership link per owner. On save it may attach this owner to a body that already exists. The service does not ask whether any other owner has the URL, and it does not branch on which adapter is plugged in.

## The file

The first adapter creates the file at page size 8192 and then sets WAL. [0003](../../../adr/0003-owner-database-is-wal.md) records why, and [Sqlite.md](Sqlite.md) records what SQLite's own docs say about the sidecars and about a later asset.

The write connection opens when the article is in hand, writes, and closes. It stays closed during the download. The exportable copy is the database after a checkpoint, or a snapshot taken with `VACUUM INTO` or the backup API. Copying the `.sqlite` file while a connection is open is not the export.

Image bytes are unwritten today. A later asset is either a blob in this file or a separate file named by the row. [Sqlite.md](Sqlite.md) records that choice as open. A `data:` URI joined back into the HTML is not required.

## The row

One row is one archive. The URL is unique in the file. The row holds the URL, the title, the excerpt, the site name, and the purified HTML. An absent excerpt or site name is null. The source URL and the URL the client sent are the same column: redirects are not followed.

Those fields are the ones `SafeDocument` carries. Byline, published time, and the lead image are not on that document, so they are not stored. A pipeline warning, including a lossy encoding, is not stored. It stays in the log.

The HTTP success body stays `url` and `title`, read from this row. The excerpt, the site name, and the HTML wait for a later read.

## Two posts

The service asks the port whether this owner already has this URL.

- The archive is already stored. The pipeline does not run, and nothing is written. The response is 200 with `url` and `title` from the row.
- The archive is not stored. The pipeline runs. After `PipelineSuccess`, the connection opens, the row is inserted, and the connection closes. The response is 201.

Two posts that both miss the row both fetch. The first insert commits. The second insert hits the unique URL, reads the stored row, and returns 200. The HTML from the second fetch is discarded. The client sees no error. A busy timeout on the connection covers that short insert, so the second writer waits for the first commit rather than failing busy. A different URL does not wait.

If the first post stores nothing, the second post's own fetch still runs. Overlapping failures stay independent. Each client receives its own outcome.

Refreshing a page that has changed is a later command. This POST does not replace a row that is already there.

`PipelineNoContent` and a thrown orchestrator failure do not reach the write. A failed insert does not report an archive.
