**Status:** ready-for-agent

## Problem Statement

The archive command can replace the final library orchestrator in a test, and still has to speak that library's success, empty page, and failure. A reader of the command must know the reader pipeline to see how an Article becomes an Archive. The HTTP client already receives the right archive or the right failure code. That wire behavior is not the gap.

## Solution

The fetch seam returns an Article or throws an archive failure. The archive command copies that Article into the Archive and never names a library type. The adapter behind the seam talks to the orchestrator and maps every library outcome. The owner still gets the same POST result: 201 for a new archive, 200 for one already stored, and the same failure codes as today.

## User Stories

1. As a client of Eleanor, I want the first POST of a URL pointer to stay 201 with `url` and `title`, so that hiding the reader does not change a new archive.
2. As a client of Eleanor, I want a second POST of that URL pointer to stay 200 with the same JSON and without a second fetch, so that an archive already stored is still the result.
3. As a client of Eleanor, I want a POST of a different URL pointer to stay 201 and to fetch that page, so that each URL pointer still has its own archive.
4. As a client of Eleanor, I want `url` in the JSON to stay the string I sent, so that the Article does not rename the archive.
5. As a client of Eleanor, I want `title` to stay `""` when the page has no title, so that `title` is always present.
6. As a client of Eleanor, I want the JSON to stay only `url` and `title`, so that the excerpt, the site name, and the article HTML stay in the archive.
7. As a client of Eleanor, I want a title that contains a quote or a backslash to stay valid JSON, so that the body stays encoded.
8. As a client of Eleanor, I want an empty string, a string of spaces, and `not-a-url` to stay off the success JSON, so that a rejected URL pointer is still not an archive.
9. As a client of Eleanor, I want a page with no article, and an article that is empty after purification, to stay off the success JSON and out of the store, so that a miss is still not stored.
10. As a client of Eleanor, I want each existing failure code to stay the code a client already reads, so that the seam change is not a new error contract.
11. As a client of Eleanor, I want a site that answers with an error status to keep that status number in the message, so that `upstream_status` still reports it.
12. As a client of Eleanor, I want a failed fetch to leave the next POST free to fetch again, so that there is still no negative cache.
13. As a client of Eleanor, I want the owner email to stay out of the JSON, so that the success body does not gain a field.
14. As a developer of Eleanor, I want the fetch seam to return an Article, so that the archive command does not read a library success.
15. As a developer of Eleanor, I want that Article to be the purified body, so that the glossary term is the value the command stores.
16. As a developer of Eleanor, I want the Article to carry a title, an optional excerpt, an optional site name, and the purified HTML, so that those are the only fields copied into the archive.
17. As a developer of Eleanor, I want the Article to have no URL, so that the URL pointer stays the name of the archive.
18. As a developer of Eleanor, I want the Article to carry no pipeline warning, so that a lossy encoding does not change the stored copy.
19. As a developer of Eleanor, I want `excerpt` and `siteName` to stay null when the page has none, so that absence is not an empty string.
20. As a developer of Eleanor, I want a failed fetch to throw `ArchiveException`, so that an empty page and a library failure leave the seam as the same kind of failure.
21. As a developer of Eleanor, I want a URL the guard calls syntax to be `url_syntax`, so that a malformed pointer stays that code.
22. As a developer of Eleanor, I want a URL the guard calls policy to be `url_policy`, so that a disallowed scheme or credentials stay that code.
23. As a developer of Eleanor, I want a URL the guard refuses, and a guard failure with no finer reason, to be `url_rejected`, so that both stay one code.
24. As a developer of Eleanor, I want a page with no article to be `extract_no_content`, so that the empty reason stays distinct from a failed extract.
25. As a developer of Eleanor, I want an article that is empty after purification to be `sanitized_empty`, so that purification emptying the body stays its own code.
26. As a developer of Eleanor, I want a redirect to be `redirect`, so that the client is still told to send the final URL.
27. As a developer of Eleanor, I want a non-HTML page to be `content_type`, so that the content-type refusal stays that code.
28. As a developer of Eleanor, I want a page over the size limit to be `body_too_large`, so that the size refusal stays that code.
29. As a developer of Eleanor, I want a site that cannot be reached to be `transport`, so that a transport failure stays that code.
30. As a developer of Eleanor, I want a site error status to be `upstream_status` and to keep the status number, so that the presenter can still name it.
31. As a developer of Eleanor, I want an encoding failure to be `encoding`, an extract failure to be `extract`, and a purify failure to be `sanitize`, so that those stages keep their codes.
32. As a developer of Eleanor, I want an unnamed library failure, and a fetch failure with no finer reason, to be `unexpected`, so that an unknown library outcome stays the generic code.
33. As a developer of Eleanor, I want a thrown library failure kept as the cause of `ArchiveException`, so that the original failure is still available behind the archive failure.
34. As a developer of Eleanor, I want an empty page to become `ArchiveException` with no cause, so that a miss is not given a library exception it did not throw.
35. As a developer of Eleanor, I want any other throwable from the fetch to pass through, so that an unexpected throwable is still not relabeled as an archive failure.
36. As a developer of Eleanor, I want the archive command to copy the Article onto an Archive whose `url` is the URL pointer, so that the stored name is the pointer and the body is the Article.
37. As a developer of Eleanor, I want the archive command constructed without the failure mapper, so that only the adapter maps library failures.
38. As a developer of Eleanor, I want the archive command to name no library type, so that a change in the reader pipeline stops at the adapter.
39. As a developer of Eleanor, I want the adapter to be the only caller of the orchestrator, so that the final class stays behind one seam.
40. As a developer of Eleanor, I want the library unchanged, so that this work does not reopen the reader's pipeline.
41. As a developer of Eleanor, I want a test double of the fetch seam to return an Article or throw `ArchiveException`, so that a command test does not build a library document.
42. As a developer of Eleanor, I want a URL with no registered double outcome to throw `ArchiveException` with `url_rejected`, so that the double refuses an unknown pointer the way a coarse guard refusal does.
43. As a developer of Eleanor, I want the HTTP test to keep driving `POST /api/archives` through the kernel with that double, so that the route is still proved without a network fetch.
44. As a developer of Eleanor, I want the adapter tested through an orchestrator built from test collaborators, so that the final class can still be exercised at the constructor the library documents.
45. As a developer of Eleanor, I want those adapter tests to be the test surface for the library translation, so that a separate mapper test is not a second surface for the same behavior.
46. As a developer of Eleanor, I want a failed save to stay `archive_not_written`, so that store failures remain the command's concern and not the fetch seam's.
47. As a developer of Eleanor, I want the domain log of the command to stay as it is, so that this seam change does not add or drop a log line.
48. As a developer of Eleanor, I want the quality checks to pass, so that the deepened seam meets the same bar as the command.

## Implementation Decisions

- This contract deepens the fetch seam recorded in [adr/0009](../../adr/0009-fetch-seam-returns-an-article.md). The module design in [ArchiveCommand.md](specs/ArchiveCommand.md) adopts that ADR. [adr/0001](../../adr/0001-archive-outcome-status.md) still owns the status classes. [adr/0002](../../adr/0002-second-post-returns-stored-archive.md) still owns the second POST. The wire codes do not change.
- The fetch interface takes a URL pointer and returns an Article. A failure throws `ArchiveException`. It does not return a library success or a library empty page.
- An Article has a title, an optional excerpt, an optional site name, and the purified HTML. It has no URL and no warnings. The archive command builds the Archive from it. The Archive's `url` is the URL pointer's string. The other fields are copied. `excerpt` and `siteName` stay null when the Article has none.
- The adapter calls the library orchestrator with the pointer's string. A library success becomes an Article by the field copy above. Warnings are dropped. A library empty page and a library orchestrator failure become `ArchiveException` through the existing failure mapper. The adapter constructs that mapper. Any other throwable passes through.
- The failure mapper stays a class. The archive command does not take it. The mapper's codes stay: guard syntax, policy, and rejected; extract-no-content and sanitized-empty; redirect, content type, and body too large; transport and upstream status with the status number; encoding, extract, sanitize, and unexpected. A guard or fetch failure with no finer reason stays `url_rejected` or `unexpected`, as the mapper already decides. A thrown library failure stays the cause. An empty page has no cause.
- The archive command's constructor is the store, the fetch seam, and the lock. `archive` still looks up, fetches on a miss, saves, and returns written or already stored. It does not catch a library type. A save failure stays `archive_not_written`.
- The fetch double's success registration takes an Article. Its failure registration takes an `ArchiveException`. A URL with no registration throws `ArchiveException` with `url_rejected`. The count of fetches stays. The HTTP test and the command test both use that double.
- The library does not change. `Orchestrator` stays final. The adapter test builds one from test collaborators.

## Testing Decisions

- A good test asserts what crosses the seam: an Article, an Archive, an `ArchiveException` with its code and cause, an HTTP status, and a JSON body. It does not assert a private method, a library type inside the archive command, or the text of a log line from the reader.
- The command seam stays the archive command, with the fetch double. Prior art is the existing command test: already stored skips the fetch, a success stores the pointer as `url`, a failure stores nothing, and a later call may fetch again. The fixtures become Articles. They stop building library documents.
- The HTTP seam stays the kernel test of `POST /api/archives`. Prior art is the existing route test. It registers an Article or an `ArchiveException` on the double. The cases for 201, 200, a second URL, an empty title, a quoted title, and a non-success body stay. It does not build a library outcome.
- The adapter seam is new and is the highest seam that still sees the reader. Prior art is the failure-mapper test, which this seam replaces. An orchestrator built from test collaborators drives one success, both empty reasons, each library failure code, a failure with no finer reason, the upstream status number, and a throwable that is not a library failure. The success asserts the Article fields and that a warning is absent. The failures assert the code and whether a cause is present.
- The route's error-document test stays on the HTTP seam. Where it used to hand the double a library throwable, it hands an `ArchiveException`. The code-to-status table is not re-proved through the orchestrator.
- Quality checks pass. They are an acceptance check, not a test.

## Out of Scope

A change to the library. A second production fetch adapter. Deleting the fetch seam and calling the orchestrator from the command. Store failures other than leaving `archive_not_written` where it is. The HTTP status classes, the second-POST rule, the lock, the owner database, the domain log lines, and the firewall. URL normalization. Refreshing a page that has changed. Folding the failure presenter into the error reply.

## Further Notes

The module spec and the ADR already record the decision. This contract is the behavior an implementer follows. The code still returns library types from the fetch seam until that work lands.
