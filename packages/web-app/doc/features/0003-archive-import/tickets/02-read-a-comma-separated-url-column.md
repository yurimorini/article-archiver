# 02: Read a comma-separated URL column

**Feature:** [0003-archive-import](../feature.md)

**Contract:** [archive-import.md](../specs/archive-import.md)

**What to build:** An operator points `archive:import` at a comma-separated file. With no column option, the column named `url` is archived, which is the Raindrop CSV export. `--field` chooses another header. `--index` chooses a column by position, starting at 0, and the first row is data. A bad combination fails before any archive.

**Blocked by:** [01 — Import a URL list](01-import-a-url-list.md)

**Status:** ready-for-agent

**Type:** enhancement

- [ ] A comma-separated file whose first row contains `url`, with neither `--field` nor `--index`, archives that column. The header row is not archived and is not counted as skipped.
- [ ] A header `id,title,note,excerpt,url` works with no column flag. The other cells are not stored.
- [ ] `--field NAME` selects the first trimmed header cell equal to that name. `URL` does not match `url`. A name with no match fails before any archive.
- [ ] `--index 0` is the first column and the first row is data. `--index 4` selects the fifth field. `00` is index 0.
- [ ] A quoted comma stays inside its field, and `""` is one quote. A leading UTF-8 BOM still matches `url`. A CRLF line does not leave a CR in the URL.
- [ ] One line with two or more fields makes the whole file comma-separated, including an unquoted comma in a URL. A row that lacks the chosen column is skipped.
- [ ] `--field` together with `--index` fails before any archive. Either option on a one-field file fails before any archive. A comma-separated file with no `url` cell and neither option fails before any archive. A negative index, or a value that is not an integer, fails before any archive.
- [ ] Those early failures use the same non-zero status as ticket 01, print no totals, and leave a pre-existing `error.log` in place.
- [ ] Quality checks pass.
