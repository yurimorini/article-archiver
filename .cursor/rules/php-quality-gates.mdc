---
description: Run php-cs-fixer and PHPStan via Docker after every PHP implementation
alwaysApply: true
---

# PHP quality gates

After finishing any implementation that adds or changes PHP code, **do not claim completion** until both tools pass with fresh evidence.

All PHP tooling runs **inside Docker** (not on the host PHP). Prefer the `bin/` wrappers.

## Required commands

```bash
./bin/quality
```

Or separately:

```bash
./bin/cs-check
./bin/phpstan
```

Optional auto-fix before re-check:

```bash
./bin/cs-fix
./bin/quality
```

If the image is missing, build then install deps first:

```bash
./bin/docker-build
./bin/composer-install
```

## Gate rules

1. Run quality checks on the final code via Docker (fresh run, not assumed).
2. Exit code must be **0**; read the full output.
3. If either check fails: fix, then re-run `./bin/quality`.
4. Only then may you report the implementation as done.
5. Do not skip these gates for "small" changes, fixtures, or tests.
6. Do not use host `php` / host `composer` for project verification.

## Scope

Applies whenever `src/`, `tests/`, or other project PHP files were created or modified in the task.
