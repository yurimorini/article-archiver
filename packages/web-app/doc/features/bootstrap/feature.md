# Feature: Eleanor package bootstrap

## Goal

Eleanor is a Composer project at `packages/web-app`. It uses the same tooling, doc layout, and test layout as the library. The PHP namespace is not chosen. There is no PSR-4 map.

The product name is Eleanor. The launcher key stays `web-app`. The Composer package name is `yumo/eleanor`. Those three strings are independent. Changing the PHP prefix later is a replace of `namespace` and `use` lines plus the two autoload keys, once those keys exist. The directories under `src/` stay put, because the prefix will map to `src/`.

## Layout

```text
packages/web-app/
  composer.json
  composer.lock
  phpstan.neon.dist
  phpunit.xml.dist
  .php-cs-fixer.dist.php
  src/                  # application code; empty until the namespace is chosen
  tests/                # PHPUnit; empty until the namespace is chosen
  bin/                  # php-cs-fixer lists this directory
  doc/features/bootstrap/
```

`src/` and `tests/` contain no PHP. `./bin/quality web-app` therefore skips Composer and exits 0. The first class adds the autoload keys and the PHP files together.

## Spec

`[specs/Bootstrap.md](specs/Bootstrap.md)`

## Current status

| Area | State |
| --- | --- |
| Tooling | Composer, PHPUnit, PHPStan, and php-cs-fixer configs are in place. Dev dependencies match the library. |
| Library link | Path repository on `../article-reader`, requirement `yumo/log-read`. |
| Namespace | `Yumo\Eleanor` mapped to `src/`. `Yumo\Eleanor\Tests` mapped to `tests/`. |
| Application code | `Yumo\Eleanor\Kernel` boots. Framework is Symfony 8.1. |
| Framework | Symfony 8.1 micro skeleton. Flex require `8.1.*`. |
| Image | Shared `log-read-php` image. A per-package Dockerfile is a later decision. |

## Out of scope

- Choosing the PHP namespace and adding PSR-4.
- Choosing Symfony, Laravel, or another framework.
- Splitting the root Dockerfile into per-package images.
- Application behavior.
