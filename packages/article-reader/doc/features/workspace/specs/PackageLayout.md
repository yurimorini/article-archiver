# Package layout

This document is the design spec for splitting the repository into two Composer packages under one git root, and for the shared launcher that runs their tooling.

The library pipeline is unfinished. This spec moves the library as it exists today. It does not redesign `src/`, the `Yumo\LogRead\` namespace, or the foundation feature specs. Further pipeline work adds files under the library package after the move.

## Layout

One git repository. The git root is the Cursor workspace.

```text
/
  AGENTS.md
  .agents/
  .cursor/
  bin/                         # launcher only
  Dockerfile                   # shared development image
  packages/article-reader/     # library, current tree moved here
  packages/web-app/            # Eleanor; see packages/web-app/doc/features/bootstrap/specs/Bootstrap.md
```

### Library

`packages/article-reader/` receives the current library without a module or namespace restructure.

Moved as they are:

- `composer.json`, `composer.lock`
- `src/`, `tests/`
- `phpstan.neon.dist`, `phpunit.xml.dist`, `.php-cs-fixer.dist.php`
- `doc/` → `packages/article-reader/doc/`, same internal tree (`features/foundation/specs`, `plans`, `context`, and this file)

The Composer package name stays `yumo/log-read`. The PSR-4 namespace stays `Yumo\LogRead\` → `src/` and `Yumo\LogRead\Tests\` → `tests/`. PHPStan, php-cs-fixer, and PHPUnit configs keep paths relative to the package (`src`, `tests`). They work because the launcher sets the working directory to the package.

`src/` will keep receiving pipeline feature files. Those features stay specified by `packages/article-reader/doc/features/foundation/specs/`.

`bin/run` moves to `packages/article-reader/bin/run`. It is the pipeline spike, not a launcher command. Its `require` of `../vendor/autoload.php` still resolves to the library `vendor/` after the move. The spike is not rewritten here.

### Web app

The layout change left `packages/web-app/` as an empty placeholder (`.gitkeep` only). The Eleanor bootstrap replaced that placeholder. The living contract is `packages/web-app/doc/features/bootstrap/specs/Bootstrap.md`: Composer package `yumo/eleanor`, the library path requirement, the same PHP tooling, and no PSR-4 map until the namespace is chosen.

### Gitignore

The root `.gitignore` already lists `vendor/`, `.composer/`, `.phpunit.cache/`, and `.php-cs-fixer.cache` without anchoring them to the repository root. Those patterns cover the library package. This change does not add a second ignore file.

## Agent paths

`AGENTS.md` lives at the git root. Superpowers skills treat it as the user preference that overrides their default folders.

`AGENTS.md` states:

- Do not create `docs/superpowers/specs/` or `docs/superpowers/plans/`.
- Specs and plans go in the `doc/` of the package whose files the task changes.
- Library work, including the unfinished pipeline: `packages/article-reader/doc/features/<feature>/specs/` and `packages/article-reader/doc/features/<feature>/plans/`. The pipeline feature folder stays `features/foundation/`.
- App work, once `packages/web-app` has a `doc/` tree: the same `doc/features/<feature>/specs/` and `.../plans/` layout under `packages/web-app/doc/`.
- The package is the one that owns the files being edited. A pipeline task writes under `article-reader`. An app task writes under `web-app`.

This spec is workspace layout, not a pipeline stage. It lives at `doc/features/workspace/specs/PackageLayout.md` and moves with `doc/`, so its path after the move is `packages/article-reader/doc/features/workspace/specs/PackageLayout.md`.

## Cursor rules

Two rules are updated in the same change so they follow the library.

`.cursor/rules/php-docs.mdc` globs become:

```text
packages/article-reader/src/**/*.php,packages/article-reader/tests/**/*.php
```

`.cursor/rules/php-quality-gates.mdc` keeps the commands `./bin/quality`, `./bin/cs-check`, `./bin/phpstan`, `./bin/cs-fix`, `./bin/docker-build`, and `./bin/composer-install`. Its scope, currently `src/` and `tests/`, becomes `packages/article-reader/src` and `packages/article-reader/tests`.

App globs are added when `packages/web-app` contains PHP. They are not added while the directory is empty.

## Launcher

`bin/` at the git root is only the launcher. One development image, built from the root `Dockerfile` by `./bin/docker-build`. The image provides PHP, extensions, and the Composer binary. PHPUnit, PHPStan, and php-cs-fixer come from the package `vendor/`, not from the image. The default image name stays `log-read-php`.

### Package list

`bin/projects` is the only list of packages the launcher runs. One package per line: name, then a path relative to the git root, separated by whitespace. Blank lines and lines whose first non-whitespace character is `#` are ignored.

Initial contents:

```text
article-reader	packages/article-reader
```

`packages/web-app` joined the list when its `composer.json` was created:

```text
web-app	packages/web-app
```

A listed path must contain a `composer.json`. If it does not, that job fails.

### Commands

`quality`, `test`, `phpstan`, `cs-check`, `cs-fix`, and `composer-install` read `bin/projects`.

- No arguments: run every listed package, in parallel.
- A listed name (`./bin/quality article-reader`): run that package only.
- A name that is not in the list: exit non-zero and print the known names.

Each job mounts the git root at `/workspace` and sets the working directory to that package. The command is the Composer script of that package (`composer --working-dir=… quality`, `test`, `cs-check`, `cs-fix`, `phpstan`). `composer-install` runs `composer install` in the package directory. Composer then uses that package's `vendor/bin`.

`quality`, `cs-check`, `cs-fix`, and `phpstan` share one skip: if that package has no `*.php` files under its own `src/` or `tests/`, the job exits 0 and does not call Composer. The search does not look at other packages. When files exist, `quality` runs the Composer `quality` script (cs-check, then phpstan). The other three run the Composer script of the same name.

`./bin/composer` always requires a package name (`./bin/composer article-reader update`). It is not fanned out across the list. Arguments after the name are passed to Composer.

`./bin/docker-run` always requires a package name, then the command (`./bin/docker-run article-reader php -v`). It is the primitive the other scripts use: same mount, same working directory, `COMPOSER_HOME` set to that package's `.composer` directory (`/workspace/packages/article-reader/.composer`).

### Parallel runs

Every selected job runs to completion. The launcher does not stop the others when one fails. The process exit code is non-zero if any job's exit code is non-zero. Each job's output is printed as one block, with the package name on the first line, so the streams do not interleave.

Per-package `COMPOSER_HOME` keeps parallel `composer install` runs from sharing one cache.

## Connecting the two packages

The layout change did not create the web app Composer file. The Eleanor bootstrap did. The connection rules below still hold. The full manifest, including PHP `^8.5`, dev tooling, and the absent autoload map, is `packages/web-app/doc/features/bootstrap/specs/Bootstrap.md`.

The dependency is one way. The app requires the library. The library does not require the app.

The library manifest is already the connectable package: `"name": "yumo/log-read"` in `packages/article-reader/composer.json`.

`packages/web-app/composer.json` declares a path repository on the sibling directory and requires that name:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../article-reader",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "yumo/log-read": "@dev"
  },
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

`../article-reader` is relative to the app `composer.json`. `composer install` in the app installs the library's runtime dependencies into `packages/web-app/vendor` and symlinks `vendor/yumo/log-read` to `packages/article-reader`. The library `require-dev` packages are not installed there. The app's PHPUnit, PHPStan, and php-cs-fixer belong in the app `require-dev`.

`minimum-stability: dev` is required because the path package resolves as a development version. `prefer-stable: true` keeps other dependencies on stable releases.

`bin/projects` includes the `web-app` line. `./bin/quality` and `./bin/composer-install` include the app. The Docker mount is the git root, so the symlink target stays inside the mount.

The app framework is still unchosen. The PHP namespace is unset, so the manifest has no autoload map.

## Verification

After the layout move, with the library as the only listed package, these checks passed: `./bin/docker-build`, `./bin/composer-install` into the library, `./bin/quality` and `./bin/quality article-reader` exit 0, and `./bin/quality web-app` was unknown. The bootstrap replaced that last check. `web-app` is now a listed package. With no PHP under its `src/` or `tests/`, `./bin/quality web-app` exits 0 and skips Composer.

## Out of scope

- Choosing Symfony or Laravel.
- Redesigning library modules, finishing pipeline features, or rewriting `bin/run`.
