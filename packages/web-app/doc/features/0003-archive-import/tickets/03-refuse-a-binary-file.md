# 03: Refuse a binary file

**Feature:** [0003-archive-import](../feature.md)

**Contract:** [archive-import.md](../specs/archive-import.md)

**What to build:** An operator who passes a binary file gets a refusal before any archive. `ext-fileinfo` classifies the file. A media type that does not start with `text/` is binary. When that extension is missing, or `finfo` cannot classify the file, a NUL byte in the first 8192 bytes is enough to refuse it.

**Blocked by:** [01 — Import a URL list](01-import-a-url-list.md)

**Status:** ready-for-agent

**Type:** enhancement

- [ ] A file whose media type does not start with `text/` is refused before any archive.
- [ ] `text/plain`, `text/csv`, and `text/html` are read. A `text/html` file is read as lines, not as a bookmark document.
- [ ] The classification uses `ext-fileinfo` when it is present.
- [ ] When `ext-fileinfo` is missing, or `finfo` cannot classify the file, a NUL byte in the first 8192 bytes is refused, and a file with no NUL byte in that window is read.
- [ ] A binary refusal prints a cause, prints no totals, leaves a pre-existing `error.log` in place, archives nothing, and exits with the same non-zero status as the other early failures.
- [ ] Quality checks pass.
