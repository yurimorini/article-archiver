# Owner-scoped service resolver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `CreateArchiveController` receives the owner's `ArchiveService` as an action argument and has no constructor.

**Architecture:** A global `ValueResolverInterface` implementation builds the service from `OwnerProvider` and `ArchiveServiceFactory`. Autoconfigure tags it. The controller drops its two dependencies. HTTP behaviour does not change.

**Tech Stack:** PHP 8.5, Symfony 8.1 HttpKernel, PHPUnit 11, `./bin/test`, `./bin/quality`.

**Spec:** `packages/web-app/doc/features/0002-server/specs/OwnerScopedService.md`

## Global Constraints

- Before Task 1, set slice 5b to `In progress` in `feature.md`.
- Run tests with `./bin/test web-app`. The last task also runs `./bin/quality`. PHP tooling runs in Docker only.
- The resolver is global, not targeted. It adds no attribute to the argument and no entry to `config/services.yaml` or `config/services_test.yaml`.
- The resolver answers only for the type `Yumo\Eleanor\Archive\ArchiveService`. Any other type, and a missing type, returns an empty array.
- The resolver does not read the request.
- Status, body, headers, and logs of `POST /api/archives` do not change. `CreateArchiveRouteTest`, `LogApiRequestTest`, and `ProductionWiringTest` are not edited.
- Docs and comments in English. Public classes and methods have JSDoc-style docblocks, like the neighbouring classes.

## Review Focus

1. A resolver that answers for every argument would hand an `ArchiveService` to a `CreateArchiveBody` argument. Task 1 `test_ignores_an_argument_of_another_type` expects `[]`.
2. An argument with no type declared gives `getType()` null. Task 1 `test_ignores_an_argument_with_no_type` expects `[]` and no error.
3. A resolver that caches the service would give owner B the archives of owner A when the provider changes. Task 1 `test_builds_the_service_for_the_owner_of_the_provider` resolves with two providers and expects that owner B's service does not see owner A's archive.
4. A resolver registered at a priority above 100 can run before `RequestAttributeValueResolver`. Task 3 step 1 reads the order from the container.

## File map

- Create: `packages/web-app/src/Api/Resolver/ArchiveServiceResolver.php` — builds the owner's service for the action argument.
- Create: `packages/web-app/tests/Api/Resolver/ArchiveServiceResolverTest.php` — unit test, no kernel.
- Modify: `packages/web-app/src/Api/Controller/CreateArchiveController.php` — no constructor, service as argument.
- Modify: `packages/web-app/doc/features/0002-server/feature.md` — slice 5b status, "Application code", "Specs and plans".

---

### Task 1: ArchiveServiceResolver

**Files:**
- Create: `packages/web-app/src/Api/Resolver/ArchiveServiceResolver.php`
- Test: `packages/web-app/tests/Api/Resolver/ArchiveServiceResolverTest.php`

**Interfaces:**
- Consumes: `Yumo\Eleanor\Api\Owner\OwnerProvider::owner(): Owner`. `Yumo\Eleanor\Archive\ArchiveServiceFactory::create(Owner): ArchiveService`.
- Produces: `final class ArchiveServiceResolver implements ValueResolverInterface`. Constructor `(OwnerProvider $owners, ArchiveServiceFactory $archives)`. `resolve(Request $request, ArgumentMetadata $argument): array` returns `[]` or `[ArchiveService]`.

- [ ] **Step 1: Write the failing test.** Class `Yumo\Eleanor\Tests\Api\Resolver\ArchiveServiceResolverTest`. Build `ArgumentMetadata` with `new ArgumentMetadata('archives', ArchiveService::class, false, false, null)`. Build the factory with `FakeArticleFetcher` and `new InMemoryArchiveStoreFactory(new InMemoryArchiveShelf())`, as `ArchiveServiceFactoryTest` does. Use a small anonymous `OwnerProvider` returning a chosen `Owner`. Tests:
  - `test_builds_the_service_for_the_owner_of_the_provider`: the fetcher returns `PipelineFixtures::success()` for `https://example.com/a`. Resolve twice with a provider for `ada@example.com`. Archiving that URL through the first service gives `ArchiveOutcome::Written`, through the second gives `AlreadyStored`. Resolve once with a provider for `bob@example.com` on the same shared factory. That service archives the URL as `Written`.
  - `test_returns_a_single_archive_service`: the result is an array with one item, an instance of `ArchiveService`.
  - `test_ignores_an_argument_of_another_type`: `ArgumentMetadata('body', CreateArchiveBody::class, false, false, null)` returns `[]`.
  - `test_ignores_an_argument_with_no_type`: `ArgumentMetadata('value', null, false, false, null)` returns `[]`.
  - Pass `new Request()` as the request.
- [ ] **Step 2: Run `./bin/test web-app`.** Expected: FAIL, class `ArchiveServiceResolver` not found.
- [ ] **Step 3: Implement `ArchiveServiceResolver` in `src/Api/Resolver/`.** Namespace `Yumo\Eleanor\Api\Resolver`. Final class with a class docblock that says it builds the service of the current owner for one controller argument. `resolve` compares `$argument->getType()` with `ArchiveService::class` and returns `[$this->archives->create($this->owners->owner())]` on a match, otherwise `[]`.
- [ ] **Step 4: Run `./bin/test web-app`.** Expected: all PASS.
- [ ] **Step 5: Commit** `feat: add the archive service argument resolver`.

### Task 2: Controller takes the service as an argument

**Files:**
- Modify: `packages/web-app/src/Api/Controller/CreateArchiveController.php`

**Interfaces:**
- Consumes: `ArchiveServiceResolver` from Task 1. The container registers it by autoconfigure.
- Produces: `create(CreateArchiveBody $body, ArchiveService $archives): JsonResponse`. The class has no constructor.

- [ ] **Step 1: Run `./bin/test web-app`.** Expected: all PASS. This is the baseline before the change.
- [ ] **Step 2: Edit `CreateArchiveController`.** Remove the constructor and the imports of `ArchiveServiceFactory` and `OwnerProvider`. Add the `ArchiveService $archives` parameter after the body, with no attribute. Replace the service call with `$archives->archive($url)`. Keep the `MapRequestPayload` attribute on the body, the route, the status and JSON helpers, and the docblock note that `ArchiveException` is not caught. Remove the constructor's docblock together with the constructor. Update the method docblock only if it names the factory.
- [ ] **Step 3: Run `./bin/test web-app`.** Expected: all PASS, including `CreateArchiveRouteTest`, `LogApiRequestTest`, and `ProductionWiringTest`. If one fails with "Controller requires a value for argument $archives", the resolver is not registered or does not run. Check Task 3 step 1.
- [ ] **Step 4: Commit** `refactor: pass the owner's archive service to the action`.

### Task 3: Check the order, update the docs, run the gates

**Files:**
- Modify: `packages/web-app/doc/features/0002-server/feature.md`

- [ ] **Step 1: Check the resolver order.** Run `./bin/docker-run web-app php bin/console debug:container debug.argument_resolver.inner`. Expected: `ArchiveServiceResolver` is listed, and it is below `RequestAttributeValueResolver` (priority 100). If it is above, set a lower priority with `#[AsTaggedItem(priority: ...)]` and add a line to the spec.
- [ ] **Step 2: Update `feature.md`.** Slice 5b status `Done` with a link to the plan. In "Application code": the controller has no constructor and receives `ArchiveService` from `ArchiveServiceResolver`. In "Specs and plans": add the [Owner-scoped service](specs/OwnerScopedService.md) spec and this plan. In the slice merge section, add one line that records why slice 5b replaced the controller's constructor dependencies.
- [ ] **Step 3: Run `./bin/quality`.** Expected: exit 0, both `cs-check` and `phpstan` clean. If `cs-check` fails, run `./bin/cs-fix`, then `./bin/quality` again. A PHPStan failure is fixed in the code, not suppressed.
- [ ] **Step 4: Run `./bin/test web-app`.** Expected: all PASS.
- [ ] **Step 5: Commit** `docs: record slice 5b`.

## Self-review

- **Spec coverage:** Resolver module and its three cases map to Task 1. The controller change maps to Task 2. Configuration (no change), priority check, and the document list map to Task 3. "Out of scope" adds no task. The resolved-owner-failure and the generic resolver are not built.
- **Types:** `ArchiveServiceResolver::resolve` returns `[]` or `[ArchiveService]` in Tasks 1 and 2. The action argument is `ArchiveService $archives` in the spec and in Task 2.
- **Proportion:** three small tasks, each with a test or an existing suite as its gate.
