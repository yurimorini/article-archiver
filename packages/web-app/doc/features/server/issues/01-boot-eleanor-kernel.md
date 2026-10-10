# 01: Boot Eleanor's kernel in the test environment

**What to build:** A developer of Eleanor can boot the application from the existing Composer project. Symfony 8.1's micro skeleton is installed there, and the quality tools and the library link stay. The kernel class is `Yumo\Eleanor\Kernel`. Dev is the default environment, the test run forces the test environment and its own fixed secret, and prod is selected by setting the environment. The shared env file commits an empty application secret, the dev env file commits a generated secret, and machine-local env files stay off git. The owner email, the bearer token, a database path, and an archive directory are absent. The console reports the application. One test boots the kernel and sees the test environment. Quality checks pass, and the bootstrap record says the namespace and the framework exist.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

**Type:** enhancement

- [x] The existing Eleanor Composer project gains Symfony 8.1's micro skeleton. The quality tools and the library link stay. The website pack, Maker, the debug bundle, the web profiler, Monolog, and the PHPUnit bridge are not added.
- [x] The framework bundle, runtime, dotenv, YAML, and console packages, and Flex's require setting, are constrained to `8.1.*`. Contrib recipes stay off. Composer may run the Flex plugin and the runtime plugin. The ctype and iconv extensions are required.
- [x] The application namespace is `Yumo\Eleanor` and the test namespace is `Yumo\Eleanor\Tests`. The kernel class is `Yumo\Eleanor\Kernel`. The console and the front controller boot that kernel.
- [x] Service and environment configuration is the official 8.1 framework recipe, with only the kernel class and the service-resource prefix renamed. Application classes in that namespace are services with autowire and autoconfigure. The parameter section is empty. No Eleanor service is defined.
- [x] The kernel allows only `prod`, `dev`, and `test`. Dev is the default. The test run forces `test` and a fixed secret of `test`. Prod is selected by setting the environment.
- [x] The shared env file commits dev as the environment and an empty application secret. The dev env file commits a generated secret and is loaded only for dev. Machine-local env files, including the compiled local env file, are gitignored. There is no secrets vault.
- [x] These env files do not carry the owner email, the bearer token, a database path, or an archive directory.
- [x] The test environment uses the recipe's framework test mode and mock session. The dev environment exposes the recipe's framework error route. The cache adapter is the filesystem in every environment. The recipe's session default and shared-directory setting stay as the recipe wrote them.
- [x] The recipe's editor settings for this package are kept. The agent-instruction files that recipe copies into the package are removed.
- [x] Existing PHPStan and formatter configuration stay. The PHPUnit configuration of the library stays, except the test-run bootstrap, which is the Symfony recipe’s `tests/bootstrap.php`. Existing quality scripts stay. Flex may add its cache-clear and asset-install scripts beside them.
- [x] One PHPUnit test, in the web-app suite, boots the kernel and sees that the environment is `test`. It does not assert file contents, namespace strings inside configuration, or an HTTP status.
- [x] The console about command exits 0, and the package quality command exits 0.
- [x] The bootstrap feature's status records that the namespace and the framework exist.
- [x] Moving to the next Symfony minor is outside this ticket.

## Comments

Slice 1 is Done. The acceptance boxes above are checked because that slice shipped. The triage `Status` stays `ready-for-agent` because this tracker has no completed label.
