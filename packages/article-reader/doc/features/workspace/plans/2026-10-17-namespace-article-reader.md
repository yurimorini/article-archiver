# Namespace rename Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Change the article-reader PHP namespace from `Yumo\LogRead` to `Yumo\ArtcleReader`.

**Architecture:** One textual replace of the namespace prefix, including the JSON-escaped form. PSR-4 still maps the prefix onto `src/` and `tests/`. No directory move. No class rename.

**Tech Stack:** PHP 8.5, Composer PSR-4, Docker launchers in `bin/`.

**Spec:** The user named the target namespace `Yumo\ArtcleReader`. `packages/article-reader/doc/features/workspace/specs/PackageLayout.md` still says `Yumo\LogRead` and is updated by the same replace.

## Global Constraints

- Target prefix is exactly `Yumo\ArtcleReader` (spelling `Artcle`, not `Article`).
- Test prefix is exactly `Yumo\ArtcleReader\Tests`.
- Composer package name stays `yumo/log-read`.
- User-Agent string `LogRead/0.1` stays. Do not replace a bare `LogRead`.
- `Yumo\Eleanor` stays.
- Do not move files under `src/` or `tests/`.
- Do not commit.
- Do not restore or edit `.devcontainer/devcontainer.json`.
- Do not edit this plan file.
- PHP checks run only through `bin/` inside Docker. No host `php` or host `composer`.

## Review Focus

- `LogRead/0.1` in `FetchPolicy` must still be the User-Agent. A prefix-only replace leaves it.
- `yumo/log-read` in both `composer.json` files and in docs must stay.
- `packages/web-app/composer.lock` stores the library PSR-4 map with doubled backslashes. That map must change with `composer.json`.
- `Yumo\Eleanor` must stay.
- After the replace, `rg` finds `Yumo\LogRead` only inside this plan file.

---

### Task 1: Replace the namespace prefix

**Files:**

- Modify: every text file under the git root that contains `Yumo\LogRead` or the two-backslash form `Yumo\\LogRead`, except this plan.
- Known hits: `packages/article-reader/src/**/*.php`, `packages/article-reader/tests/**/*.php`, `packages/article-reader/composer.json`, `packages/web-app/composer.lock`, and the markdown files under `packages/article-reader/doc/features/foundation/` and `packages/article-reader/doc/features/workspace/` that name the old prefix.
- Do not modify: `packages/article-reader/doc/features/foundation/specs/HttpFetcher.md` (only `LogRead/0.1`).

**Interfaces:**

- Consumes: prefix `Yumo\LogRead` and escaped prefix `Yumo\\LogRead`.
- Produces: prefix `Yumo\ArtcleReader` and escaped prefix `Yumo\\ArtcleReader`. `composer.json` autoload keys `"Yumo\\ArtcleReader\\": "src/"` and `"Yumo\\ArtcleReader\\Tests\\": "tests/"`.

- [x] **Step 1: Replace both spellings with one script**

Run from the git root. Skip `.git`, `vendor`, `node_modules`, and this plan file. Replace the two-backslash form first, then the one-backslash form. Touch a file only when its text changes.

```python
python3 << 'PY'
from pathlib import Path
root = Path("/home/yuri/data/workspace/article-archiver")
skip_dirs = {".git", "vendor", "node_modules"}
skip_name = "2026-10-17-namespace-article-reader.md"
pairs = (
    ("Yumo\\\\LogRead", "Yumo\\\\ArtcleReader"),
    ("Yumo\\LogRead", "Yumo\\ArtcleReader"),
)
changed = 0
for path in root.rglob("*"):
    if not path.is_file():
        continue
    if skip_dirs.intersection(path.parts) or path.name == skip_name:
        continue
    data = path.read_bytes()
    if b"\0" in data:
        continue
    text = data.decode("utf-8")
    new = text
    for old, repl in pairs:
        new = new.replace(old, repl)
    if new != text:
        path.write_text(new, encoding="utf-8")
        changed += 1
        print(path)
print(f"changed {changed}")
PY
```

- [x] **Step 2: Confirm the leftover old prefix is only this plan**

```bash
rg -n 'Yumo\\+LogRead' --glob '!**/2026-10-17-namespace-article-reader.md' --glob '!vendor/**' --glob '!.git/**'
```

Expected: no matches.

```bash
rg -n 'LogRead/0\.1' packages/article-reader/src/HttpFetcher/FetchPolicy.php packages/article-reader/tests/HttpFetcher/FetchPolicyTest.php
rg -n 'yumo/log-read' packages/article-reader/composer.json packages/web-app/composer.json
rg -n 'Yumo\\Eleanor' packages/web-app/src/Kernel.php
```

Expected: User-Agent, package name, and Eleanor namespace still present.

- [x] **Step 3: Rebuild autoload inside Docker**

```bash
./bin/composer article-reader dump-autoload
./bin/composer web-app dump-autoload
```

Expected: both exit 0.

- [x] **Step 4: Run library quality and tests**

```bash
./bin/quality article-reader
./bin/test article-reader
```

Expected: both exit 0.

- [x] **Step 5: Run web-app quality and tests**

The lockfile PSR-4 map changed. Eleanor PHP did not.

```bash
./bin/quality web-app
./bin/test web-app
```

Expected: both exit 0.

Do not commit.
