# Package layout implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Put the existing library in `packages/article-reader` and run its tooling from a root launcher that reads `bin/projects`.

**Architecture:** One git root. `bin/` is only the launcher: it reads `bin/projects`, mounts the git root in the existing `log-read-php` image, and sets each job's working directory and `COMPOSER_HOME` to that package. The library tree moves as it is. `packages/web-app` is an empty placeholder.

**Tech Stack:** Bash launcher, Docker image `log-read-php`, Composer scripts already declared in the library `composer.json`.

**Spec:** `doc/features/workspace/specs/PackageLayout.md` (after the move: `packages/article-reader/doc/features/workspace/specs/PackageLayout.md`)

## Global Constraints

- One git repository. The git root stays the Cursor workspace.
- Composer package name stays `yumo/log-read`. PSR-4 stays `Yumo\LogRead\` → `src/` and `Yumo\LogRead\Tests\` → `tests/`.
- Do not redesign `src/`, the namespace, or foundation feature specs. Do not rewrite `bin/run` (it is not in this tree).
- Do not create `packages/web-app/composer.json`, `vendor/`, or `doc/`. The only file there is `.gitkeep`.
- Do not add a second `.gitignore`. Existing unanchored `vendor/`, `.composer/`, `.phpunit.cache/`, and `.php-cs-fixer.cache` cover the package.
- Do not create `docs/superpowers/specs/` or `docs/superpowers/plans/`.
- Default image name stays `log-read-php`. PHPUnit, PHPStan, and php-cs-fixer come from the package `vendor/`, not the image.
- `bin/projects` initial contents are one line: `article-reader`, tab, `packages/article-reader`. `web-app` is not listed.
- Blank lines and lines whose first non-whitespace character is `#` are ignored.
- A listed path with no `composer.json` fails that job.
- No arguments: every listed package, in parallel. A listed name: that package only. An unknown name: exit non-zero and print the known names.
- Every selected job runs to completion. Exit non-zero if any job exits non-zero. Each job's output is one block with the package name on the first line.
- `quality`, `cs-check`, `cs-fix`, and `phpstan` skip (exit 0, no Composer) when that package has no `*.php` under its own `src/` or `tests/`. The search does not look at other packages or at `bin/`.
- `test` and `composer-install` do not use that skip.
- `./bin/composer` and `./bin/docker-run` always require a package name and are not fanned out.
- `COMPOSER_HOME` for a job is `/workspace/<package-path>/.composer`. The git root is mounted at `/workspace`. Working directory is the package.
- Quality commands call the Composer script of the same name. `composer-install` runs `composer install`.
- Cursor rule globs for PHP docs become `packages/article-reader/src/**/*.php,packages/article-reader/tests/**/*.php`.
- PHP quality-gate scope becomes `packages/article-reader/src` and `packages/article-reader/tests`. The command names stay `./bin/quality`, `./bin/cs-check`, `./bin/phpstan`, `./bin/cs-fix`, `./bin/docker-build`, and `./bin/composer-install`.

## File structure

- `bin/projects` — package list.
- `bin/lib/projects.sh` — parse the list and resolve a name.
- `bin/lib/dispatch.sh` — skip rule, parallel fan-out, output blocks.
- `bin/docker-run` — package name, then the command. Sets mount, workdir, `COMPOSER_HOME`.
- `bin/docker-build` — unchanged.
- `bin/quality`, `bin/test`, `bin/phpstan`, `bin/cs-check`, `bin/cs-fix`, `bin/composer-install` — thin wrappers over `dispatch_composer_jobs`.
- `bin/composer` — one package, then Composer arguments.
- `bin/tests/launcher_test.sh` — bash tests. No Docker, no new packages.
- Moved unchanged into `packages/article-reader/`: `composer.json`, `composer.lock`, `src/`, `tests/`, `phpstan.neon.dist`, `phpunit.xml.dist`, `.php-cs-fixer.dist.php`, `doc/`.
- `packages/article-reader/bin/.gitkeep` — the moved php-cs-fixer config still lists `__DIR__/bin`, and `bin/run` is not in the tree. The directory has to exist or the fixer aborts.
- `packages/web-app/.gitkeep`
- Modify: `AGENTS.md`, `.cursor/rules/php-docs.mdc`, `.cursor/rules/php-quality-gates.mdc`, the same two texts under `.agents/rules/`, `.devcontainer/devcontainer.json`.

## Task 1: Project list

**Files:**
- Create: `bin/lib/projects.sh`
- Test: `bin/tests/launcher_test.sh` (parser cases)

**Interfaces:**
- Consumes: a projects file path.
- Produces:
  - `projects_read <file>` prints `name<TAB>path` lines.
  - `projects_names <file>` prints one name per line.
  - `projects_path_for <file> <name>` prints the relative path and returns 0, or returns 1 when the name is absent.

- [x] Write parser tests: blank lines, `#` comments (including indented), tab and space separators, unknown name returns 1.
- [x] Run `bash bin/tests/launcher_test.sh` and confirm the parser cases fail because `projects_read` is missing.
- [x] Implement `bin/lib/projects.sh`.
- [x] Re-run the parser cases and confirm they pass.

## Task 2: Fan-out

**Files:**
- Create: `bin/lib/dispatch.sh`
- Create: `bin/projects`
- Modify: `bin/quality`, `bin/test`, `bin/phpstan`, `bin/cs-check`, `bin/cs-fix`, `bin/composer-install`, `bin/composer`
- Test: `bin/tests/launcher_test.sh`

**Interfaces:**
- Consumes: `projects_read`, `projects_names`, `projects_path_for`.
- Produces: `dispatch_composer_jobs <root> <kind> [package] [composer-args...]`
  - `kind` is `quality`, `cs-check`, `cs-fix`, `phpstan`, `test`, or `install`.
  - Unknown package: stderr is `Unknown package: <name>` then one known name per line, exit 1, no job output.
  - Each job block is the package name, a newline, then that job's combined output.
  - Blocks are printed in list order after every job has finished.
  - Skip stdout line: `No PHP files under src/ or tests/; skipping.`
  - Missing manifest stderr line: `Package <name> has no composer.json at <rel>/composer.json`
  - Composer invocation: `composer --working-dir=/workspace/<rel> <script-or-install> [args...]` via `<root>/bin/docker-run <name> ...`.

- [x] Extend tests for unknown name, single package, all packages, skip, sibling PHP ignored, PHP only under `bin/` ignored, missing `composer.json`, parallel failure still runs the sibling, `test` and `install` do not skip, `./bin/composer` requires a name and forwards the rest.
- [x] Run the tests and confirm the new cases fail.
- [x] Implement dispatch and the thin wrappers. `bin/projects` is `article-reader`, tab, `packages/article-reader`.
- [x] Re-run `bash bin/tests/launcher_test.sh` and confirm it passes.

## Task 3: docker-run

**Files:**
- Modify: `bin/docker-run`
- Test: `bin/tests/launcher_test.sh`

**Interfaces:**
- Consumes: `projects_path_for`.
- Produces: `./bin/docker-run <package> <command> [args...]`.
  - Fewer than 2 arguments: stderr `Usage: ./bin/docker-run <package> <command> [args...]`, exit 1.
  - Unknown package: same message as Task 2, exit 1.
  - Missing image: `Docker image '<name>' not found. Build it with: ./bin/docker-build`, exit 1.
  - Run args include `-v <root>:/workspace`, `-w /workspace/<rel>`, `-e COMPOSER_HOME=/workspace/<rel>/.composer`, image `log-read-php` unless `IMAGE_NAME` is set.
  - Keep the existing rootless versus host-user branch.

- [x] Add tests that put a fake `docker` on `PATH`.
- [x] Run them and confirm they fail against the old script (it has no package argument).
- [x] Rewrite `bin/docker-run`.
- [x] Re-run the full launcher test script.

## Task 4: Move the library and point the docs at it

**Files:**
- Move into `packages/article-reader/`: `composer.json`, `composer.lock`, `src/`, `tests/`, `phpstan.neon.dist`, `phpunit.xml.dist`, `.php-cs-fixer.dist.php`, `doc/`.
- Create: `packages/article-reader/bin/.gitkeep`, `packages/web-app/.gitkeep`
- Modify: `AGENTS.md`, both PHP doc rule files, both PHP quality-gate rule files, `.devcontainer/devcontainer.json`

**Interfaces:**
- Consumes: the launcher from Tasks 1–3.
- Produces: the layout in the spec. `postCreateCommand` becomes `composer install --working-dir=packages/article-reader` because that hook runs inside the dev container, not through `./bin/docker-run`.

- [x] `git mv` the library files. Do not edit them.
- [x] Add the two `.gitkeep` files.
- [x] Update `AGENTS.md` with the package doc paths from the spec.
- [x] Update the four rule files' globs and scope.
- [x] Update the devcontainer install command.
- [x] Confirm `packages/web-app` contains only `.gitkeep`.

## Task 5: Spec verification

- [x] `bash bin/tests/launcher_test.sh` exits 0.
- [x] `./bin/docker-build` builds `log-read-php`.
- [x] `./bin/composer-install` creates `packages/article-reader/vendor`.
- [x] `./bin/quality` and `./bin/quality article-reader` exit 0.
- [x] `./bin/quality web-app` exits non-zero and its output contains `article-reader`.
- [x] `packages/web-app` has `.gitkeep` and no `composer.json` or `vendor/`.

## Self-review

- Spec coverage: layout, gitignore non-change, agent paths, both Cursor rules, package list, fan-out commands, skip rule, composer and docker-run, parallel blocks, one-way dependency left as a contract (no app `composer.json`), verification commands. `bin/run` is absent, so there is nothing to move; Task 4 creates the package `bin/` directory the existing fixer config already names.
- Placeholder scan: none.
- Type consistency: `dispatch_composer_jobs` kinds match the wrapper scripts. `install` is the kind; the Composer command is `install`.
