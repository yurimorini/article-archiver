**Status:** ready-for-agent

## Problem Statement

Eleanor is a Composer project with quality tools and no application code. The PHP namespace is unset, the framework is not installed, and nothing boots. Later server work needs a kernel, a console, a front controller, and the standard environment and env-file layout before it can add the archive route.

## Solution

Install the Symfony 8.1 micro skeleton into the existing project with Flex. Choose the PHP namespace. Keep the recipe’s service and environment configuration, with the namespace renames that make it Eleanor’s. Prove the kernel boots in the test environment. Leave the archive route, logging, the firewall, and the process runtime for their own slices.

## User Stories

1. As a developer of Eleanor, I want the application namespace chosen and mapped onto the application tree, so that the first classes have one stable prefix.
2. As a developer of Eleanor, I want the test namespace mapped onto the test tree, so that the boot test and later tests share that prefix.
3. As a developer of Eleanor, I want the kernel class to live in the application namespace, so that the console and the front controller boot Eleanor’s kernel.
4. As a developer of Eleanor, I want Symfony installed into the existing Composer project, so that the quality tools and the library link stay in place.
5. As a developer of Eleanor, I want the micro skeleton, so that Eleanor starts as an HTTP application without a website stack.
6. As a developer of Eleanor, I want Flex to write the base configuration from the official recipes, so that the layout matches current Symfony practice.
7. As a developer of Eleanor, I want the Symfony packages pinned to the 8.1 line, so that installs stay on the current stable minor.
8. As a developer of Eleanor, I want contrib recipes to stay off unless someone turns them on, so that a later package does not apply a third-party recipe silently.
9. As a developer of Eleanor, I want Composer allowed to run the Flex and runtime plugins, so that the install can finish without a plugin prompt.
10. As a developer of Eleanor, I want the ctype and iconv extensions required by the project, so that the skeleton’s platform needs are declared.
11. As a developer of Eleanor, I want the existing PHPUnit, PHPStan, and formatter setup kept, so that quality checks stay the ones this repo already runs.
12. As a developer of Eleanor, I want the existing quality scripts kept when Flex adds its own scripts, so that cache and asset commands do not replace the quality commands.
13. As a person running Eleanor, I want the kernel to allow the dev, test, and prod environments, so that those three modes are the only ones the application accepts.
14. As a person running Eleanor, I want dev to be the default environment, so that a local run boots in dev without extra configuration.
15. As a developer of Eleanor, I want the test run to force the test environment, so that tests do not boot in dev.
16. As a person running Eleanor, I want prod to be selected by setting the environment, so that the recipe’s prod mode exists before the runtime slice.
17. As a person running Eleanor, I want the shared env file to commit an empty application secret, so that prod has no baked-in secret.
18. As a person running Eleanor, I want a generated secret committed for the dev environment only, so that a dev boot has a secret without copying the prod one.
19. As a person running Eleanor, I want machine-local env files ignored by git, so that a real secret stays on the machine.
20. As a developer of Eleanor, I want the test run to set its own fixed secret, so that the boot test does not read the dev secret.
21. As a developer of Eleanor, I want the owner email absent from these env files, so that the owner stays unconfigured until the token slice.
22. As a developer of Eleanor, I want the bearer token absent from these env files, so that authentication stays out of the skeleton.
23. As a developer of Eleanor, I want no database path in these env files, so that storage configuration stays with the storage slice.
24. As a developer of Eleanor, I want no archive directory in these env files, so that the place an archive is stored is not decided here.
25. As a person running Eleanor, I want secrets kept in the gitignored env file, so that Eleanor does not gain a second encrypted secret store.
26. As a developer of Eleanor, I want application classes in the chosen namespace registered as services with autowire and autoconfigure, so that later classes join the container without a handwritten definition.
27. As a developer of Eleanor, I want no Eleanor service defined yet, so that the archive service arrives in its own slice.
28. As a developer of Eleanor, I want the test environment to turn on framework test mode and the mock session, so that a test boot does not use the real session storage.
29. As a developer of Eleanor, I want the dev environment to expose the framework error route, so that the recipe’s dev error page is wired and is not Eleanor’s archive route.
30. As a person running Eleanor, I want the application cache on the filesystem in every environment, so that the skeleton does not depend on Redis or another server.
31. As a person running Eleanor, I want a front controller, so that a later slice can serve HTTP without inventing the entry point.
32. As a person running Eleanor, I want the console to report the application, so that a boot can be checked from the command line.
33. As a developer of Eleanor, I want the recipe’s editor settings kept for this package, so that indentation and line endings match the recipe.
34. As a developer of Eleanor, I want the recipe’s copied agent-instruction files removed, so that this repo’s own agent instructions stay the ones agents read.
35. As a developer of Eleanor, I want one test that boots the kernel and sees the test environment, so that the boot is proven without an HTTP call.
36. As a developer of Eleanor, I want the quality checks to pass on the new PHP, so that the skeleton meets the same bar as the library.
37. As a developer of Eleanor, I want the recipe’s session default left as the recipe wrote it, so that the skeleton does not invent a second session policy.
38. As a developer of Eleanor, I want the recipe’s shared directory setting left as the recipe wrote it, so that the skeleton does not invent a storage path for archives.
39. As a developer of Eleanor, I want the bootstrap record updated when this slice lands, so that a later session sees that the namespace and the framework exist.
40. As a developer of Eleanor, I want a later move to the next Symfony minor to be a separate constraint change, so that this slice does not float off 8.1.

## Implementation Decisions

- The host project is the existing Eleanor Composer project. Flex is added there. The project is not replaced by a new skeleton directory.
- The Symfony line is 8.1. The framework bundle, runtime, dotenv, YAML, and console packages are constrained to `8.1.*`. Flex’s require setting is the same line. A move to the next minor is outside this contract.
- Contrib recipes are not allowed. Composer is allowed to run the Flex plugin and the runtime plugin.
- The ctype and iconv extensions are required.
- The packages are the micro-skeleton set. The website pack, Maker, the debug bundle, the web profiler, Monolog, and the PHPUnit bridge are not required. No extra PHPStan extension is added.
- The existing PHPUnit, PHPStan, and formatter configuration stay. The existing quality scripts stay. Flex may add its cache-clear and asset-install scripts beside them.
- The application namespace is `Yumo\Eleanor`, mapped to the application tree. The test namespace is `Yumo\Eleanor\Tests`, mapped to the test tree. The kernel class is `Yumo\Eleanor\Kernel`.
- Service and environment configuration is the official 8.1 framework recipe. The only edits are the two namespace renames: the kernel class, and the service-resource prefix. The parameter section stays empty. No archive service is defined.
- The kernel allows `prod`, `dev`, and `test`.
- Test-mode framework settings and the mock session are the recipe’s test block. The dev-only framework error route is the recipe’s dev route. The cache adapter is the filesystem in every environment.
- The shared env file commits `APP_ENV=dev` and an empty `APP_SECRET`. The dev env file commits the generated secret and is loaded only for dev. Machine-local env files, including the compiled local env file, are gitignored.
- The test run sets `APP_ENV=test` and a fixed `APP_SECRET=test`.
- These files do not carry the owner email, the bearer token, a database path, or an archive directory. There is no secrets vault.
- The recipe’s editor settings for the package are kept. The agent-instruction files the same recipe copies into the package are deleted.
- The recipe’s session default and its shared-directory setting stay as the recipe wrote them.
- When the slice is done, the bootstrap feature’s status records that the namespace and the framework exist.

## Testing Decisions

- A good test observes behavior at the boot seam. It boots the kernel and sees that the environment is `test`. It does not assert file contents, namespace strings inside configuration, or an HTTP status.
- The only automated seam is that kernel boot, run by the existing PHPUnit suite. The web-app suite is the seam. It is empty today. The library’s PHPUnit tests are the prior art for how this repo writes a test. They cover library modules, so they are not reused as the boot seam.
- `bin/console about` exiting 0, and the package quality command exiting 0, are acceptance checks of the same boot. They are not a second test seam.

## Out of Scope

- The archive route, and any temporary HTTP server that would answer it.
- Monolog and request logging.
- The firewall, the bearer token, and the owner email.
- PHP-FPM and the reverse proxy.
- Twig, HTML pages, the web profiler, Maker, and the Symfony CLI.
- A secrets vault.
- Storage, the SQLite file, and where an archive is written.
- Adopting the archive-outcome, second-post, WAL, or per-owner lookup decisions. Those stay where they are until a later slice’s contract adopts them.
- Changing the article-reader glossary. This contract adds no domain term.

## Further Notes

- Symfony 8.1 is the current stable. Its bug-fix and security support end in January 2027. The next minor is the November 2026 release. This contract pins 8.1 on purpose and leaves the bump for later. No ADR is required: 8.1 is Symfony’s recommendation for most new projects.
- The dev error route is framework wiring for the dev error page. It is not `POST /api/archives`.
- The image already runs PHP 8.5, which satisfies Symfony 8.1.
- Glossary terms for this package stay as they are. Archive, article, URL pointer, and owner are not configured by this slice.
