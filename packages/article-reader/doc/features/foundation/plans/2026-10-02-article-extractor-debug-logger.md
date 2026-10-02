# Extension: ArticleExtractor debug logger (no vendor `error_log`)

**Date:** 2026-10-02  
**Feature:** [Foundation pipeline](../feature.md)  
**Amends:** [ArticleExtractor](../specs/ArticleExtractor.md) (Phase 4)  
**Supersedes:** the original `ExtractPolicy::$debug` → Readability `error_log()` contract in that spec and in [plans/2026-09-22-article-extractor.md](../plans/2026-09-22-article-extractor.md)

This is a tracked amendment of the living ArticleExtractor spec. The current mapping lives in the spec. This file records what changed and why.

## Why

Readability.php 4.x has two independent debug channels: vendor `debug` writes `Reader: (Readability) …` via `error_log()` (CLI stderr); an optional PSR-3 logger receives the same messages even when vendor `debug` is false.

The original Phase 4 contract forwarded policy `debug` to both channels. `debug: true` with a test logger therefore printed grab-article traces during `./bin/test`. The original plan called that stderr expected. It is not useful in a library: without a logger, debug only pollutes stderr; with a logger, `error_log` is a duplicate.

## Original contract

From the ArticleExtractor spec (before this extension) and the Phase 4 plan:

- `ExtractPolicy::$debug` maps to Readability `debug` → `error_log()`.
- When `debug` is true, the factory may also pass a PSR-3 logger into Readability.
- A debug-off extractor must pass `logger: null` (Readability’s PSR-3 logger emits even when vendor `debug` is false).
- `test_logger_receives_messages_when_debug_is_true` may print `Reader: (Readability)` lines on stderr. That was not a test failure.

## New contract

`ExtractPolicy::$debug` only means “forward the injected PSR-3 logger”. Vendor `debug` is never enabled.

| Policy `debug` | Injected logger | Vendor `debug` (`error_log`) | Vendor `logger` |
|----------------|-----------------|------------------------------|-----------------|
| `true` | none | `false` (forced) | `null` |
| `true` | present | `false` | the logger |
| `false` | present or none | `false` | `null` |

`debug: true` with no logger is treated as off. Orchestrator still passes its logger into `ArticleExtractor` only when extract `debug` is on.

## Unchanged

- Logger stays a constructor collaborator, not a policy field.
- Debug-off still withholds the logger.
- `fixRelativeURLs`, `charThreshold`, `maxElemsToParse` are unchanged.
- Orchestrator hop logging is unchanged.

## Tests that lock the amendment

- `test_logger_is_not_called_when_debug_is_false`
- `test_logger_receives_messages_when_debug_is_true`
- `test_debug_true_without_logger_does_not_write_error_log`
- `test_debug_true_with_logger_does_not_write_error_log`
