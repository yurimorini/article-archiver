# SQLite for one file per owner

This file records a research session. It is not a final decision. A later session can replace it. A spec adopts it when implementation starts.

The question is what SQLite's own documentation says about keeping one owner's URL list and archived pages in one SQLite file, including a future asset that is larger than the HTML. Three blocks follow: what those pages say, what this repository does with images today, and what this session reads that as for Eleanor. The third block is an implication, not a statement from SQLite.

## What SQLite's own docs say

### A blob in the database, or the same bytes in their own file

[Internal Versus External BLOBs](https://www.sqlite.org/intern-v-extern-blob.html) (measurements on SQLite 3.7.8, 2011-09-19; page last updated 2022-04-18) compares reading blobs stored in the database with reading the same bytes from separate files, while the database still holds the filename. The ratio is time-in-files divided by time-in-database. Above 1.0, the database read is faster. The page's rule of thumb: a page size of 8192 or 16384 works best for large blob I/O; blobs smaller than 100KB are faster in the database; blobs larger than 100KB are faster as separate files. The same page says to remeasure on the target machine. An update on that page, for SQLite 3.19.0 (2017-05-22), says SQLite was about 35% faster than direct disk I/O for both reads and writes of 10KB blobs.

The table on that page is the page-size caveat behind the 100KB line. At 4096-byte pages the 100KB column is 0.890 (separate file faster). At 8192 and 16384 it is 1.035 and 1.023 (database still slightly faster). At 4096-byte pages a 10KB blob is 2.261.

[35% Faster Than The Filesystem](https://www.sqlite.org/fasterthanfs.html) (measured the week of 2017-06-05 on a build between 3.19.2 and 3.20.0; page last updated 2025-11-13) repeats the small-blob result from the other direction: the key is the filename, so the file-read side never opens the database. For about 10KB blobs, SQLite reads and writes were about 35% faster than `fread` / `fwrite`, and one database of those blobs used about 20% less disk space than one file per blob, because files are padded to the filesystem block and the database packs them. The 35% figure is approximate. The page says some machines show higher latency for SQLite, and that a cold filesystem cache is a case where direct I/O looks better. Its take-away is that SQLite latency is competitive with individual files, often faster. The filesystem tends to win as blobs get larger, because `open()` and `close()` are then spread over more bytes. Runs that put each blob in its own transaction were much slower than direct I/O. The same page says that when another engine tells you to store a filename and then open that file, SQLite is much faster if the bytes are stored in the database instead, and it points back to the 2011 article for that shape (database consulted, then a file opened).

[SQLite As An Application File Format](https://www.sqlite.org/appfileformat.html), which the [WAL page](https://www.sqlite.org/wal.html) links, uses the same 100KB line: SQLite reads and writes smaller blobs (less than about 100KB) faster than those blobs as separate files, citing the two pages above. It also says an SQLite database is one file that can be copied or moved, and that a pile of files has no single file that is the document.

### Length, page size, and where a large blob sits

[Implementation Limits](https://www.sqlite.org/limits.html) (page last updated 2026-07-10; well-defined limits since about 3.5.8, 2008-04-16):

| Limit | Published value |
| --- | --- |
| Default maximum bytes in a string or BLOB (`SQLITE_MAX_LENGTH`) | 1,000,000,000 |
| Hard maximum the current implementation will support | 2,147,483,645 (2^31−3). Some functions, such as `hex()`, can fail earlier |
| Maximum bytes in a row | The same `SQLITE_MAX_LENGTH`. During part of `INSERT` and `SELECT`, the whole row is encoded as one BLOB |
| Default maximum bytes in an SQL statement | 1,000,000,000. A multi-million-byte literal inside `INSERT` is the wrong path; bind the value |
| Page size | A power of two from 512 to 65536. The default used for the size example is 4096 |
| Maximum pages | 4,294,967,294. That has also been the default since 3.45.0 (2024-01-15) |
| Maximum database file | About 17.5 terabytes at 4096-byte pages, about 281 terabytes at 65536-byte pages. The upper bound is untested on hardware that large |

[The database file format](https://www.sqlite.org/fileformat2.html) (the format since 3.0.0, 2004-06-18; page last updated 2025-12-25) says that when a b-tree cell's payload does not fit on the page, the surplus spills onto overflow pages in the same database file. Those pages are a linked list. A large blob does not require a second file in order to exist in the database.

[Incremental blob I/O](https://www.sqlite.org/c3ref/blob_open.html) opens the blob that `SELECT column FROM table WHERE rowid = ?` would return, by database name, table, column, and rowid, then reads or writes a range. The SQL text does not contain the bytes. The handle cannot change the blob's size; `UPDATE` does that. `zeroblob` creates a zero-filled blob to fill through that handle.

### One database file, and the files beside it

The [WAL page](https://www.sqlite.org/wal.html) (WAL since 3.7.0, 2010-07-21; page last updated 2026-08-25) says a connection defaults to `journal_mode=DELETE`. WAL is persistent once set. While a WAL-mode database is open, SQLite keeps a `-wal` file and a `-shm` shared-memory file next to it. The `-wal` name is the database name plus `-wal`. That pair is the reason the page gives for WAL being less attractive as an application file format.

The `-wal` file usually disappears when the last connection closes: that connection checkpoints and deletes the WAL and the `-shm` file. If the last process exits without closing the connection, or if the persistent-WAL file control is set, the `-wal` file can remain. It is part of the database's persistent state. Copying or moving the database without it can drop committed transactions or leave the database corrupt. The only removal the page calls safe is to open the database with `sqlite3_open()` and then `sqlite3_close()`.

In rollback mode the sidecar is a `-journal` file. [Atomic Commit](https://www.sqlite.org/atomiccommit.html) (this article is rollback mode, not WAL; page last updated 2026-04-21) says the commit point is the deletion of that journal. `PERSIST` mode leaves the journal in place after commit and overwrites its header; deleting that file by hand can corrupt the database if the journal is hot. [How To Corrupt An SQLite Database File](https://www.sqlite.org/howtocorrupt.html) (page last updated 2026-04-13) says SQLite normally stores content in a single disk file, and that in a quiescent state the journal does not exist and only the database file matters. If a `-journal` or `-wal` file does exist, it has to stay with that database file.

WAL does not work over a network filesystem: every process has to share the wal-index memory, so all of them have to be on the same computer. [File locking](https://www.sqlite.org/lockingv3.html) (rollback mode; page last updated 2025-05-31) and the atomic-commit page both say POSIX advisory locks are a poor fit for NFS, and both advise keeping the database off a network filesystem. Locking on Unix is POSIX advisory locks.

Checkpoints: by default, SQLite checkpoints when the WAL reaches 1000 pages, which the WAL page calls about 4MB, and again when the last connection closes. A reader that stays open can stop the checkpoint from resetting the WAL, so the `-wal` file can grow for as long as some reader is always active.

The WAL page still says WAL fits smaller transactions better, that rollback mode is likely faster above about 100MB, and that a transaction past a gigabyte may fail with an I/O or disk-full error. The next sentence on that page says that beginning with 3.11.0 (2016-02-15), WAL mode works as efficiently on large transactions as rollback mode does. Page size cannot be changed after entering WAL mode; that takes rollback-journal mode ([WAL](https://www.sqlite.org/wal.html), [VACUUM](https://www.sqlite.org/lang_vacuum.html)).

### Copying a live database

[How To Corrupt](https://www.sqlite.org/howtocorrupt.html) §1.2: a backup taken while a transaction is in progress can contain some old pages and some new pages, and that copy can be corrupt. The same section names three ways that copy a live database into a consistent file: `sqlite3_rsync` (SQLite 3.47.0, 2024-10-21, and later, over SSH), [`VACUUM INTO`](https://www.sqlite.org/lang_vacuum.html), and the [backup API](https://www.sqlite.org/backup.html). A plain copy is also safe when no transaction is in progress. If the previous write failed, the `-journal` or `-wal` file has to be copied with the database. §1.4 lists "copying a database file without also copying its journal" among the actions likely to corrupt a database. In the quiescent state, that journal is absent.

[The backup API page](https://www.sqlite.org/backup.html) (page last updated 2025-11-13) describes an older procedure: take a shared lock through the SQLite API, copy the file with an external tool, drop the lock. Writers wait until the lock is dropped. A power failure or operating-system failure during that copy can leave the backup corrupt. The online backup API copies the source into another database. Done in steps, the source is locked only while pages are read. Finishing the sequence makes the destination a byte-for-byte copy of the source as of when the copy started. A write from another connection during a stepped backup usually restarts it. When the operation completes, the destination is a consistent snapshot. A write on the same connection, to a file-backed database, is folded into the destination instead of forcing a restart.

[`VACUUM INTO`](https://www.sqlite.org/lang_vacuum.html) (page last updated 2025-07-12) writes a new database that has the same logical content, vacuumed, and leaves the original unchanged. The page calls it a consistent snapshot and an alternative to the backup API. The output is smaller, and deleted content is gone from it. The backup API uses fewer CPU cycles and can run incrementally. If `VACUUM INTO` is interrupted by shutdown or power loss, the output file might be incomplete and corrupt. When `synchronous` is `NORMAL` or `FULL`, SQLite syncs the output after the command finishes, so a power loss after completion should leave that file intact, assuming the OS, filesystem, and hardware do. The target path must be missing or an empty file.

[How To Corrupt](https://www.sqlite.org/howtocorrupt.html) §2.2: on Unix, `close()` in one thread drops POSIX advisory locks held on that file by every thread in the process. A thread that opens the database file itself, reads it, and closes it — the page's example is a backup copy that bypasses the library — can clear locks SQLite still believes it holds. SQLite's own connections avoid this among themselves. Extra defenses arrived in 3.51.0 (2025-11-04) and the page says they are not a complete cure. §2.6: opening one database through two names (hard or symbolic links) makes the two connections use different journals, so recovery looks in the wrong place.

### Space after deletes

[VACUUM](https://www.sqlite.org/lang_vacuum.html): unless `auto_vacuum=FULL`, a large delete leaves free pages in the file. The file can stay larger than its live content. `VACUUM` rebuilds the file, packs tables so they are largely contiguous, and overwrites the original. That needs as much as twice the size of the database in free disk space. Deleted bytes are usually left in place and marked reusable, so they can be recovered until `VACUUM` rewrites the file (an alternative the page names is `PRAGMA secure_delete=ON`). `auto_vacuum` can return free pages and shrink the file without a full rebuild; the page says it can fragment the file further and does not compact partially filled pages the way `VACUUM` does. `VACUUM` can also change `page_size` or `auto_vacuum` on an existing file when the database is not in WAL mode. In WAL mode, only the `auto_vacuum` property can be changed that way. Normally both are chosen before the file is created.

### Two connections writing one file

[Locking](https://www.sqlite.org/lockingv3.html), for rollback mode: many connections may hold a shared lock and read. One reserved lock means one prospective writer. An exclusive lock is required to write the database file, and no other lock may be held with it. Readers finish, then the writer runs; a pending lock stops new readers so the writer is not starved. A second writer that cannot get the reserved lock fails with `SQLITE_BUSY`. The pager treats a thread and a process the same way for this purpose.

[WAL](https://www.sqlite.org/wal.html): readers do not block the writer, and the writer does not block readers. There is still one writer, because there is one WAL file. Queries can still return `SQLITE_BUSY`: another connection has the database in exclusive locking mode; the last connection is briefly exclusive while it deletes the WAL and `-shm` files; or the first connection after a crash is recovering and a third connection arrives during that recovery.

The same page documents a WAL-reset race, present from 3.7.0 through 3.51.2 (2026-01-09), fixed in 3.51.3 (2026-03-13), with backports named for 3.44.6 and 3.50.7. It requires two or more connections on one WAL database, in different threads or processes, writing or checkpointing at the same moment. The page calls that timing rare and says the observed rate is on the order of SSD faults or cosmic-ray hits. The page was updated 2026-08-25.

The [backup API](https://www.sqlite.org/backup.html) says a `SQLITE_BUSY` on a locked file can be waited out with a busy handler or a busy timeout registered on the connection. Without that, the step fails immediately.

### Deployments these pages are warning about

The network-filesystem warnings above are about the disk the database file sits on. The one-writer lock is the concurrency model on every file. A single database that many clients write at once, on a network filesystem, is the case [locking](https://www.sqlite.org/lockingv3.html) and [atomic commit](https://www.sqlite.org/atomiccommit.html) tell you to avoid. Those pages do not say that a database on a local disk, used from one computer, inherits the NFS failure.

## What this repository does with images today

The archiver does not store image bytes.

[`HtmlSanitizerTest`](../../../../../article-reader/tests/HtmlSanitizer/HtmlSanitizerTest.php) expects an `https` image to survive as a remote URL (`src` still `https://example.com/x.png`, serialized with a space before `/>`). The same test expects `<img src="data:image/png;base64,aaa" alt="x">` to become an empty string: the element is gone, not rewritten.

[`HtmlSanitizer.md`](../../../../../article-reader/doc/features/foundation/specs/HtmlSanitizer.md) says `data:` and `file:` are off in HTMLPurifier's defaults, and this project restricts schemes to `http`, `https`, and `mailto`. Its test table says an `https` image is kept. [`PurifyPolicy`](../../../../../article-reader/src/HtmlSanitizer/PurifyPolicy.php) rejects any scheme outside those three, and [`HtmlSanitizer`](../../../../../article-reader/src/HtmlSanitizer/HtmlSanitizer.php) copies that list onto `URI.AllowedSchemes`.

[`ArticleExtractor.md`](../../../../../article-reader/doc/features/foundation/specs/ArticleExtractor.md) says the vendor fills a lead image from `og:image`, `twitter:image`, or `<link rel="image_src">`. `image` and `images` are not mapped onto `ReadableDocument`. The mapped document carries title, excerpt, site name, the article HTML, and the source URL.

## What this implies for Eleanor

These paragraphs are this session's reading. They are not claims from sqlite.org.

**The HTML body.** The documented drawback of putting bytes in SQLite shows up for large blobs, on the order of the 100KB line in the 2011 article, with the page-size table above. The 2017 article's explanation of why small blobs are faster and smaller in the database is the `open()` / `close()` each extra file pays. A separate file that still has to be opened is the arrangement those measurements found more expensive for a blob around 10KB. Whether a given article body is under 100KB is not something those pages measure.

**Assets later.** The official blob guidance does not require `data:` URIs, a side table of pieces, a join, and substituting those pieces back into the HTML when the row is read. Two associations are written down. The bytes can be a BLOB in the database file, spilled to overflow pages when they do not fit on one b-tree page, and read back by row through the incremental blob interface or as a bound value. Or the row can store a filename and the bytes can live in a separate file, which is the 2011 comparison, and the case that page found faster to read once the blob is past the 100KB line (subject to that page-size table). The 2017 page adds that if the database is consulted for the filename on every read, storing the blob in the database was the faster of the two. Rewriting HTML at query time is an application choice about how the markup names those bytes. SQLite's pages do not describe that design.

A blob and the rest of its row share one `SQLITE_MAX_LENGTH` budget, because the row is encoded as one blob during part of insert and select. The default budget is one billion bytes. The 2011 preference for 8192- or 16384-byte pages is a property of the file: WAL mode does not allow `page_size` to change afterwards.

**What still matters for one file per owner on a machine the operator controls.**

- A naive copy of the file while a transaction is in progress can mix old and new pages. Copying the database without a hot `-journal` or a leftover `-wal` can drop commits or corrupt the file. After a clean close in the default `DELETE` journal mode, the journal is gone and the database file is the whole state. In WAL mode the `-wal` and `-shm` files exist for as long as a connection is open, and the `-wal` file can remain after an unclean exit. "Copy this one file" is the quiescent file, not the file in the middle of a write.
- A consistent copy of a live database is a SQLite operation: the backup API or `VACUUM INTO`. The older locked filesystem copy makes writers wait, and a power loss during the copy can leave that backup corrupt. `VACUUM INTO` can itself leave a corrupt output if it is interrupted. From inside the same process, a thread that opens and closes the database file directly can drop the locks SQLite is using.
- Deletes leave free pages and, unless the deleted content is overwritten, the old bytes. The file the operator copies can stay large and can still contain a deleted article until `VACUUM`. `VACUUM` wants on the order of twice the file in free space.
- Two requests that write the same owner's file take turns. In the default rollback mode the writer holds an exclusive lock and the other writer gets `SQLITE_BUSY` unless the connection is set up to wait. Readers are blocked while that exclusive lock is held. In WAL mode the writer does not block readers, and there is still only one writer.

**What belongs to a different deployment.** A public server with many writers against one database, and a network filesystem as the disk, are the cases the locking and WAL pages warn about. They are not this store. One file per owner on a local disk still has the single-writer lock above; it does not pick up the NFS failure by being SQLite. Storing the HTML in the database, versus a separate file that is opened anyway, is the small-blob comparison those pages already measured, not a separate drawback.
