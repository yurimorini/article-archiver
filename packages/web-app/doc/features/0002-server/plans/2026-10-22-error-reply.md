# Error Reply Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `ErrorTranslator` owns the archive status and message tables, and `ArchiveFailurePresenter` is gone.

**Architecture:** The presenter is one adapter on a seam nothing else implements. Its two match tables move into `ErrorTranslator` as private methods. `translate(\Throwable): Reply` stays the interface. `TranslateApiException` stays the HTTP adapter. No status, wire code, or sentence changes.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit 11, `./bin/test`, `./bin/quality`.

**Spec:** `packages/web-app/doc/features/0002-server/specs/SqliteStore.md` (Errors). The index note is [Error reply](../feature.md#error-reply-2026-10-22). ADR 0001 stays.

## Global Constraints

- `ErrorTranslator` maps `ArchiveFailure::NotWritten` to 500 and the fixed sentence `The archive could not be stored.` The driver text is not in that sentence.
- A failure of `findSummary` or `find` is not wrapped. `ErrorTranslator` turns that throwable into 500 `unexpected` with `Something went wrong on the server.`
- The 400, 415, 405, 422, and 502 answers stay as slice 6 left them. ADR 0001 stays. The four status classes do not move.
- Copy the match arms from `ArchiveFailurePresenter` verbatim. Do not rewrite a status or a sentence.
- `translate(\Throwable $error): Reply<ErrorResponse>` stays the public interface. The class has no constructor. `TranslateApiException` still receives `ErrorTranslator` and is not edited.
- The reply holds fixed texts only. The text of the throwable never reaches it.
- No new glossary term. No ADR. Do not edit the current-ticket row in `feature.md`. Do not edit an executed plan. Docs and comments in English. PHP tests: `./bin/test web-app`. Final gate: `./bin/quality`. Docker only. Commit scope `web-app`, Conventional Commits.
- `feature.md` links this plan from the Error reply section. Do not add a second link.

## Review Focus

The existing tests already pin these. This plan adds no test, because `new ErrorTranslator()` already returns the same replies the move must keep. A behavior test cannot go red.

1. `NotWritten` caused by `PDOException('disk full')` stays 500 `archive_not_written` and `The archive could not be stored.` The body does not contain `disk full`. `ErrorTranslatorTest` yield `archive_not_written` and `test_failure_text_never_reaches_the_body`.
2. `UpstreamStatus` with `upstreamStatus: 503` says `The page's site answered with status 503.` With no number it says `The page's site answered with an error status.` Both stay 502 `upstream_status`. Yields `upstream_status with number` and `upstream_status without number`.
3. A `\LogicException` and a `ConflictHttpException` stay 500 `unexpected`. The logic-exception text `/etc/secret` does not reach the body. Yield `unknown throwable`, `test_other_http_errors_are_not_treated_as_bad_requests`, and `test_failure_text_never_reaches_the_body`.
4. Request rows stay on their statuses: `invalid_json` and `invalid_body` are 400, `not_found` is 404, `method_not_allowed` is 405, `unsupported_media_type` is 415. The same `translations` provider.
5. Every `ArchiveFailure` case has one yield. The private `status` and `message` matches have no `default`. Dropping an arm fails that yield.

---

### Task 1: Fold the tables into ErrorTranslator

**Files:**
- Modify: `packages/web-app/src/Api/Error/ErrorTranslator.php`
- Delete: `packages/web-app/src/Api/Error/ArchiveFailurePresenter.php`
- Modify: `packages/web-app/doc/features/0002-server/feature.md`
- Test: `packages/web-app/tests/Api/Error/ErrorTranslatorTest.php` (unchanged)

**Interfaces:**
- Consumes: `ErrorTranslator::translate(\Throwable $error): Reply<ErrorResponse>`. `ArchiveFailure`. `RequestFailure`.
- Produces: `ErrorTranslator` with no constructor. Private `status(ArchiveFailure $failure): int`, `message(ArchiveFailure $failure, ?int $upstreamStatus): string`, and `upstreamMessage(?int $upstreamStatus): string`. `archiveReply` calls `status` and `message` on `$this`. `ArchiveFailurePresenter` does not exist. `translate` is unchanged.

- [x] **Step 1: Do not add a test**

`ErrorTranslatorTest::translations` already lists every `ArchiveFailure` case and the request rows. `test_failure_text_never_reaches_the_body` already rejects `disk full`, `10.0.0.5`, and `/etc/secret`. Leave that file as it is.

- [x] **Step 2: Run `./bin/test web-app -- --filter 'ErrorTranslatorTest|TranslateApiExceptionTest'`.** Expected: PASS. This is the baseline. It is not a red bar.

- [x] **Step 3: Move the tables and delete the presenter**

Delete the constructor of `ErrorTranslator` and the `ArchiveFailurePresenter` field. `archiveReply` calls `$this->status($failure)` and `$this->message($failure, $error->upstreamStatus)`.

Add the three private methods by moving them from `ArchiveFailurePresenter`. Same signatures, same match arms, same sentences. `status` and `message` have no `default`.

Method order under `translate`: `archiveReply`, `status`, `message`, `upstreamMessage`, `requestFailure`, `badRequest`.

Delete `packages/web-app/src/Api/Error/ArchiveFailurePresenter.php`. Do not edit `TranslateApiException`.

- [x] **Step 4: Run `./bin/test web-app -- --filter 'ErrorTranslatorTest|TranslateApiExceptionTest'`.** Expected: PASS.

Search `packages/web-app/src` and `packages/web-app/tests` for `ArchiveFailurePresenter`. Expected: no matches.

- [x] **Step 5: Update the index**

In the Error reply section of `feature.md`, replace the section body with:

> Not a new slice, and not the current ticket. `ArchiveFailurePresenter` was one adapter on a seam nothing else implements. The status and message tables live in `ErrorTranslator`, the module that turns a throwable into a `Reply`. `TranslateApiException` stays the HTTP adapter. [SqliteStore.md](specs/SqliteStore.md) names `ErrorTranslator` for the `NotWritten` row. Plan: [error reply](plans/2026-10-22-error-reply.md). ADR 0001 stays. The four status classes do not move.

In the Application code cell, replace `Api\Error\{RequestFailure, ArchiveFailurePresenter, ErrorTranslator}` with `Api\Error\{RequestFailure, ErrorTranslator}`. Do not edit the current-ticket row.

- [x] **Step 6: Run `./bin/test web-app` and `./bin/quality`.** Expected: both exit 0.

- [x] **Step 7: Commit**

```bash
git add packages/web-app/src/Api/Error/ErrorTranslator.php packages/web-app/src/Api/Error/ArchiveFailurePresenter.php packages/web-app/doc/features/0002-server/feature.md
git commit -m "$(cat <<'EOF'
refactor(web-app): fold archive failure tables into the translator

EOF
)"
```
