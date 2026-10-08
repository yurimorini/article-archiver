# The owner's database is WAL, and the export is a checkpointed file

Status: proposed. This is a research direction, not a final decision. A later session can replace it. A spec adopts it when implementation starts.

The first domain store is one SQLite file per owner. WAL lets a reader proceed while one writer commits. While a connection is open, `-wal` and `-shm` sit beside the database, and copying the database without a leftover `-wal` can drop committed work. After the last connection closes, SQLite checkpoints and removes those two files. The exportable copy is that checkpointed file, or a snapshot taken with `VACUUM INTO` or the backup API. The file is created at page size 8192 before WAL is set, because page size cannot be changed afterwards, and a stored page can be as large as the fetch cap.

The default `DELETE` journal leaves a single file after a clean commit, and a raw copy matches that file more often. A writer then holds an exclusive lock and blocks readers. WAL is the choice because one owner's requests overlap, and the export is an explicit snapshot.
