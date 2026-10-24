# Kernel-booting tests live in tests/Integration

Status: accepted. The empty-route spec follows this. The kernel spec records the boot test in this namespace.

A PHPUnit test in Eleanor that boots the kernel lives in `tests/Integration/`, as a flat file. Its namespace is `Yumo\Eleanor\Tests\Integration`. Booting the kernel means constructing `Yumo\Eleanor\Kernel` and calling `boot()`, calling `KernelTestCase::bootKernel()`, or calling `WebTestCase::createClient()`. The kernel boot test and any HTTP test that uses `WebTestCase` both live here.

Symfony's testing page calls a `WebTestCase` an application test and typically places it in `tests/Controller/`. It reserves "integration test" for a `KernelTestCase` that does not cover the whole application. Eleanor uses one folder for every test that boots the kernel. The split that matters is whether the kernel starts.

A PHPUnit test that does not boot the kernel mirrors `src/` under `tests/`. There is no `tests/Unit/` directory. `tests/bootstrap.php` stays at `tests/bootstrap.php`. The article-reader package is unchanged.

## Considered options

- Place the HTTP test in `tests/Controller/` or `tests/Application/`, as the Symfony testing page describes. Rejected: one folder for every kernel boot.
- Leave the kernel boot test at `tests/KernelTest.php`. Rejected: that test boots the kernel.
- Nest `tests/Integration/Controller/Api/`. Rejected: `Integration` is the type folder. Mirroring `src/` is for tests that do not boot the kernel.

## Consequences

- The boot test namespace is `Yumo\Eleanor\Tests\Integration`.
- The empty-route HTTP test is a flat file in the same directory.
- The article-reader test layout stays as it is.
