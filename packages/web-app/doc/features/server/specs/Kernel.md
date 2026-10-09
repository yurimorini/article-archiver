# Kernel

Module design for [ticket 01](../issues/01-boot-eleanor-kernel.md). The decisions in [symfony-skeleton.md](../symfony-skeleton.md) and that ticket are closed. This slice adds no glossary term and adopts no ADR.

## Module

`Yumo\Eleanor\Kernel` is the only Eleanor type. It is the official Symfony 8.1 micro-kernel class, placed in that namespace. It boots one environment and reports that environment to its caller. The environments it accepts are `prod`, `dev`, and `test`.

The recipe’s front controller and the recipe’s console construct this kernel and boot it. They are entry points, not modules. The test does the same boot and reads the environment back.

The kernel loads the official 8.1 service and environment configuration. The only renames are the kernel class and the service-resource prefix, which is `Yumo\Eleanor\`. Classes in that namespace are services, with autowire and autoconfigure. The parameter section is empty. While the container is built, the framework aliases `Yumo\Eleanor\Kernel` to its own `kernel` service. That alias is framework wiring. This slice defines no Eleanor service. The kernel class is the only application class.

The test namespace is `Yumo\Eleanor\Tests`.

The kernel depends on the Symfony 8.1 framework bundle, runtime, dotenv, YAML, and console, installed into the existing Composer project and constrained to `8.1.*`. Flex’s require setting is the same line. The library link and the quality tools stay. Contrib recipes stay off. Composer may run the Flex plugin and the runtime plugin. The ctype and iconv extensions are required. The website pack, Maker, the debug bundle, the web profiler, Monolog, and the PHPUnit bridge are not added.

It does not depend on an archive service, storage, a token, or an owner email.

## Boot

A boot starts from a recipe entry point, or from the test. The runtime reads the environment and the secret, then constructs `Yumo\Eleanor\Kernel` with that environment and boots it.

Dev is the default. A normal run reads the shared env file, which commits `dev` and an empty secret, then the dev env file, which commits a generated secret and is loaded only for dev. Prod is the same kernel with the environment set to `prod`. The test run does not read the dev secret. It sets the environment to `test` and the secret to `test` before the kernel boots.

The booted kernel loads the recipe configuration for that environment. The framework secret comes from the secret value. The session default and the shared-directory setting stay as the recipe wrote them. The shared directory is the recipe’s setting, and this slice does not use it as an archive path. The cache adapter is the filesystem in every environment. In `test`, the recipe’s test block turns on framework test mode and the mock session. In `dev`, the recipe’s dev block exposes the framework error route.

The owner email, the bearer token, a database path, and an archive directory are absent from these env files. Machine-local env files, including the compiled local env file, stay off git. There is no secrets vault. A real secret lives in a machine-local env file.

After boot, the caller reads the environment from the kernel.

## Errors

This slice adds no Eleanor exception type and does not produce an HTTP error document.

An empty environment fails in the constructor, before boot, with PHP's `InvalidArgumentException`. An environment other than `prod`, `dev`, or `test` fails during boot, while the kernel builds its parameters, with PHP's `InvalidArgumentException`. The same failure covers an environment whose characters are not legal in a PHP class name.

An empty secret fails while the container resolves `kernel.secret`, with `Symfony\Component\DependencyInjection\Exception\EmptyParameterValueException`. The shared env file commits that empty secret on purpose, so a prod boot from the committed files fails. Dev passes because the dev env file supplies a generated secret. The test run passes because it sets the secret to `test`. A prod boot passes once a machine-local env file supplies a non-empty secret.

A missing secret variable fails while that value is resolved, with `Symfony\Component\DependencyInjection\Exception\EnvNotFoundException`. There is no secrets vault to fall back to.

The recipe’s dev error route is the framework’s dev error page. It is not a failure of this kernel, and it is not the archive route.

## Test

One test, in the web-app suite, is the only automated seam. The test run sets the environment to `test` and the secret to `test`, then the test boots `Yumo\Eleanor\Kernel` and reads the environment back. The assertion is that the environment is `test`.

The test does not read files, namespace strings inside configuration, or an HTTP status. It does not cover the empty-environment, disallowed-environment, or empty-secret failures. The library’s PHPUnit tests stay the pattern for how this repo writes a test. They are not the boot seam. The existing PHPUnit configuration stays. The PHPUnit bridge is not added.

The console about command exiting 0, and the package quality command exiting 0, are acceptance checks of the same boot. They are not a second test.

## Adopted recipe

The recipe’s editor settings for this package stay. The agent-instruction files that recipe copies into the package are removed. Existing PHPUnit, PHPStan, and formatter configuration stay. Existing quality scripts stay. Flex may add its cache-clear and asset-install scripts beside them.

When this slice is done, the bootstrap feature’s status records that the namespace and the framework exist. A move to the next Symfony minor is a separate constraint change.

## Out of scope

The archive route, logging, the firewall, the bearer token, the owner email, storage, and the process runtime. Twig, HTML pages, the web profiler, Maker, and the Symfony CLI. A secrets vault. Adopting the archive-outcome, second-post, WAL, or per-owner lookup decisions.
