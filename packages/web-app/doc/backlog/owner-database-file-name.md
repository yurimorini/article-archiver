# Owner database file name

Discussion recorded on 2026-10-24. Non-authoritative. No feature owns this note. It is not a requirement, a design, or a change to [sqlite-store.md](../features/0002-server/specs/sqlite-store.md). A later feature adopts any part of it by writing that decision in its own `feature.md`.

## What exists

One SQLite file per owner. `Owner` lowercases the email with `mb_strtolower` in UTF-8. `SqliteArchiveStoreFactory` names the file `hash('sha256', $owner->email) . '.sqlite'`: 64 hexadecimal characters. WAL adds the same name with the suffixes `-wal` and `-shm`. The email is stored in the `meta` table. `archive.written` and `archive.failed` put that path in the log field `store`. The archive log keeps the email out of log fields.

The closed spec says the name is the SHA-256 of the lowercased email. This note does not revise that sentence.

## Proposals

Two directions came up. Neither is chosen.

**Readable name.** The file name is the lowercased email. Characters that a path forbids are encoded. A normal address becomes `ada@example.com.sqlite`. A person looking at the data directory sees the owner. The same path is what `store` logs today, so the email would show up in the log unless that field changes.

**Shorter hash.** The file name is a prefix of the same SHA-256, so the email stays out of the path and out of `store`. A shorter prefix raises the chance that two different emails share one file. That chance is the birthday paradox: each owner is compared with every other owner, and the pairs grow with the square of the count. The space does not have to be exhausted for a collision to become likely.

Lengths mentioned, as illustration, not as a pick:

- 8 hex characters (32 bits). About one in a million at 100 owners. About 1.2% at 10,000 owners. About 50% near 77,000 owners.
- 12 hex characters (48 bits). About one in 500 million at 1,000 owners. About 0.18% at one million owners. About 50% near 20 million owners.

A collision means those owners open the same `.sqlite` file. A truncated SHA-256 prefix stays uniformly distributed. The figures above are the birthday count.
