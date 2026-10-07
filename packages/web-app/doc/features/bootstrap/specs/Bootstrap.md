# Eleanor package bootstrap

This document is the contract for the Eleanor Composer package. It does not choose a PHP namespace or a framework.

## Names

| What | Value |
| --- | --- |
| Product | Eleanor |
| Directory | `packages/web-app` |
| Launcher key | `web-app` |
| Composer name | `yumo/eleanor` |
| PHP namespace | Unset |

The Composer name is one field in `composer.json`. The PHP prefix is absent until the first class. Tooling configs name directories (`src`, `tests`, `bin`), not a namespace.

## Manifest

`packages/web-app/composer.json`:

- `"type": "project"`
- `"require"`: `"php": "^8.5"` and `"yumo/log-read": "@dev"`
- Path repository `../article-reader` with `"symlink": true`
- `"minimum-stability": "dev"` and `"prefer-stable": true`
- `"require-dev"`: `phpunit/phpunit` `^11.0`, `friendsofphp/php-cs-fixer` `^3.95`, `phpstan/phpstan` `^2.2`
- Scripts `test`, `cs-check`, `cs-fix`, `phpstan`, and `quality`, same commands as the library
- No `autoload` and no `autoload-dev`

`composer install` in this package installs the library runtime dependencies into `packages/web-app/vendor` and symlinks `vendor/yumo/log-read` to `packages/article-reader`. The library `require-dev` packages are not installed there.

## Tooling files

Copied from the library, with paths relative to this package:

- `phpstan.neon.dist` — level 8, paths `src` and `tests`
- `phpunit.xml.dist` — bootstrap `vendor/autoload.php`, suite directory `tests`, source directory `src`
- `.php-cs-fixer.dist.php` — finder in `src`, `tests`, and `bin`

`bin/` exists so the fixer does not abort when the first PHP file appears. It holds no command yet.

## Launcher

`bin/projects` lists:

```text
article-reader	packages/article-reader
web-app	packages/web-app
```

`./bin/quality`, `./bin/test`, `./bin/phpstan`, `./bin/cs-check`, `./bin/cs-fix`, and `./bin/composer-install` include this package. Quality commands skip when `src/` and `tests/` contain no `*.php`.

The development image stays the root `Dockerfile` and the name `log-read-php`.

## Docs and tests

Feature documents live under `packages/web-app/doc/features/<feature>/`, with `feature.md`, `specs/`, `plans/`, and `research/` when each has something to hold.

Tests will mirror modules under `tests/` the way the library does. No test class is added in this change, because a class needs a namespace.

## Cursor rules

PHP doc globs gain `packages/web-app/src/**/*.php` and `packages/web-app/tests/**/*.php` when those trees contain PHP. They stay limited to the library while both trees are empty.

## Out of scope

- PSR-4 prefix and the first class
- Framework skeleton
- A second Docker image
