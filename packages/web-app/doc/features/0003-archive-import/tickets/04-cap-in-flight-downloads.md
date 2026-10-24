# 04: Cap in-flight downloads

**Feature:** [0003-archive-import](../feature.md)

**Contract:** [archive-import.md](../specs/archive-import.md)

**What to build:** An operator can choose how many downloads run at once. The default cap is 10, across every host. `--parallel 1` and `--parallel false` run one at a time. A value the command does not allow fails before any archive. One failure does not cancel the URLs still waiting.

**Blocked by:** [01 — Import a URL list](01-import-a-url-list.md)

**Status:** ready-for-agent

**Type:** enhancement

- [ ] With `--parallel` omitted, at most 10 downloads are in flight at once, across every host.
- [ ] `--parallel` from 2 to 10 keeps that many downloads in flight.
- [ ] `--parallel 1` and `--parallel false` run one download at a time.
- [ ] `--parallel` above 10, `0`, a negative, or any other word, including `FALSE`, fails before any archive. The outcome matches the other early failures: a cause, no totals, a pre-existing `error.log` left in place, and the same non-zero status.
- [ ] A failed download does not cancel the URLs still queued.
- [ ] Downloads start in file order. The next URL starts when a slot is free.
- [ ] Two identical URLs in one run still fetch once while other URLs are in flight.
- [ ] Quality checks pass.
