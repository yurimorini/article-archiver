# The fetch seam returns an Article

Status: accepted by [Archive command](../features/0002-server/design/ArchiveCommand.md).

`Orchestrator` is final, so the archive command cannot call it and still replace it in a test. The first seam hid that class and nothing else: `ArticleFetcher` returned the library's `PipelineSuccess` or `PipelineNoContent`, and the command read those types. The adapter passed the outcome through.

The port returns an `Article` or throws `ArchiveException`. An `Article` is the purified body: a title, an optional excerpt, an optional site name, and the purified HTML. It has no URL. `ArticleReaderAdapter` calls the orchestrator and maps that outcome, including through `ArchiveFailureMapper`, which only the adapter constructs. The command does not name a library type and does not take the mapper.

Calling `Orchestrator` from the command was the alternative. The tests would then run the real pipeline, or keep building library outcomes. Keeping the pass-through was the other: the seam stayed, and the command still spoke the reader.
