# The owner database file name is 16 hexadecimal characters

Status: accepted 2026-10-31. The [SQLite store spec](../features/0002-server/specs/sqlite-store.md) adopts this.

One SQLite file per owner. The name is the first 16 hexadecimal characters of the SHA-256 of the lowercased email, plus `.sqlite`. The email stays out of the path and out of the `store` log field. Sixteen characters are 64 bits. A collision of that prefix is not a practical risk for this archive, and a file whose `meta.email` is a different owner is refused, so two owners do not share a list. A file left under the full hash is renamed to the prefix, with its `-wal` and `-shm` siblings, when the short file is absent.

The full hash is 64 characters and names the same owner with no extra safety once `meta.email` is checked. The email itself as the file name shows the owner to anyone who can list the directory, and the same path is what `store` logs. A separate index would allow a still shorter name and would be a second file to keep with the backup. Eight or twelve characters were the other prefixes. Eight collides once the count of owners leaves a household, if the check is ever skipped. Twelve is enough with the check. Sixteen keeps the check as a backstop.
