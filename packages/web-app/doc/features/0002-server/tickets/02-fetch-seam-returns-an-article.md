# 02: The fetch seam returns an Article

**Feature:** [0002-server](../feature.md)

**Contract:** [fetch-seam.md](../specs/fetch-seam.md)

**What to build:** An owner who posts a URL pointer still receives the same archive result and the same failure codes. The archive command receives an Article, the purified body with no URL, or an archive failure. It no longer speaks the reader pipeline. The adapter behind the fetch seam is the only module that maps the library outcome.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

**Type:** enhancement

- [x] The first POST of a URL pointer stays 201 with `url` and `title`, and a second POST of that pointer stays 200 without a second fetch.
- [x] `url` in the success JSON stays the string the client sent. The excerpt, the site name, and the article HTML stay out of that JSON.
- [x] An empty string, a string of spaces, a pointer that is not a URL, a page with no article, and a failed fetch stay off the success JSON and out of the store.
- [x] Each existing failure code stays the code a client already reads, including `upstream_status` with the site's status number.
- [x] The fetch seam returns an Article or throws an archive failure. The Article has a title, an optional excerpt, an optional site name, and the purified HTML, and it has no URL and no warning.
- [x] The archive command copies that Article onto the Archive and names no library type. It is constructed without the failure mapper.
- [x] The adapter maps a library success, both empty reasons, and each library failure. A thrown library failure stays the cause. An empty page has no cause. Any other throwable passes through.
- [x] The fetch double registers an Article or an archive failure. An unregistered URL pointer throws `url_rejected`. Command tests and the kernel route test stop building library documents.
- [x] The adapter test, built from an orchestrator and test collaborators, replaces the failure-mapper test as the surface for that translation.
- [x] A failed save stays `archive_not_written`. The library, the domain log, and the HTTP status classes stay as they are.
- [x] Quality checks pass.

## Comments

The fetch seam shipped on 2026-10-22. `ArticleFetcher::fetch` returns an `Article`. `ArticleReaderAdapter` maps the library outcome. `ArchiveService` takes no mapper and names no library type. The acceptance boxes above are checked because that work shipped. The triage `Status` stays `ready-for-agent` because this tracker has no completed label.
