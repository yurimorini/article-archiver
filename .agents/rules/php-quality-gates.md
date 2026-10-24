---
description: Run php-cs-fixer and PHPStan via Docker after every PHP implementation
alwaysApply: true
globs: packages/**/*.php,tests/**/*.php
---

# PHP quality gates

After finishing any implementation that adds or changes PHP code, **do not claim completion** until both tools pass with fresh evidence.

All PHP tooling runs **inside Docker** (not on the host PHP). Each package launches Composer from its own `bin/run`. The shared image is built from the repository root.

## Required commands

From the package that changed (`packages/article-reader` or `packages/web-app`):

```bash
./bin/run quality
```

Or separately:

```bash
./bin/run cs-check
./bin/run phpstan
```

Optional auto-fix before re-check:

```bash
./bin/run cs-fix
./bin/run quality
```

If both packages changed, run the same command in each package.

If the image is missing, build it from the repository root, then install dependencies from the package:

```bash
./bin/docker-build
./bin/run install
```

## Gate rules

1. Run quality checks on the final code via Docker (fresh run, not assumed).
2. Exit code must be **0**; read the full output.
3. If either check fails: fix, then re-run `./bin/run quality` in that package.
4. Only then may you report the implementation as done.
5. Do not skip these gates for "small" changes, fixtures, or tests.
6. Do not use host `php` / host `composer` for project verification.

## Scope

Applies whenever `packages/article-reader/src`, `packages/article-reader/tests`, `packages/web-app/src`, `packages/web-app/tests`, or other project PHP files were created or modified in the task.
