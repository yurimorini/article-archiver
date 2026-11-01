# Owner-scoped service resolver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One value resolver builds `ArchiveService` and `ArchiveListService` for the current owner.

**Architecture:** `OwnerScopedServiceResolver` replaces `ArchiveServiceResolver` and `ArchiveListServiceResolver`. It matches the action-argument type and calls that type's existing factory with `OwnerProvider::owner()`. The factories, the services, and `ListArchivesQueryResolver` stay as they are. Autowire registers the one class. No factory port.

**Tech Stack:** PHP 8, Symfony `ValueResolverInterface`, PHPUnit inside Docker via `./bin/run` from `packages/web-app`.

**Spec:** `packages/web-app/doc/features/0002-server/design/OwnerScopedService.md`. This plan revises that design's module: the sentence "when a second owner-scoped service exists, extract the shared step" is now due. Task 1 step 9 writes the revision. `packages/web-app/doc/features/0004-archive-list/design/ArchiveList.md` keeps the list service and changes only the resolver sentence.

**Workspace:** Implementation follows `.agents/rules/plan-workspace.md`. Record the branch checked out when work begins, then create the worktree with `using-git-worktrees`. The workspace branch starts from that branch.

## Global Constraints

- No new dependency. No edit to `config/services.yaml` or `config/services_test.yaml`.
- `ArchiveService`, `ArchiveListService`, `ArchiveServiceFactory`, `ArchiveListServiceFactory`, and `ListArchivesQueryResolver` stay. `ImportCommand` stays on `ArchiveServiceFactory`.
- Both services stay `#[Exclude]`. The resolver is global, not targeted. No attribute on the action argument.
- `resolve` does not read `Request`. `owner()` runs only in a matching `match` arm.
- Commands run from `packages/web-app` through `./bin/run` or `./bin/docker-run`. Do not use host PHP.
- Finished plans under `doc/features/` stay historical. Do not edit them.
- PHPDoc is English, complete sentences, in the voice of the two resolvers this plan deletes.

## Review Focus

None left uncovered. A non-matching argument must not call `owner()` (`test_ignores_an_argument_it_does_not_own_without_asking_the_owner`). A nullable or defaulted argument of either service type still resolves (`test_answers_a_nullable_argument_and_an_argument_with_a_default`). An owner failure escapes for either service type and no service is built (`test_lets_an_owner_failure_escape_before_any_service_is_built`). The list service follows the owner of that call (`test_returns_the_list_service_of_the_current_owner`). The old resolver classes must not stay registered (step 7).

---

### Task 1: One resolver for both owner-scoped services

**Files:**
- Create: `packages/web-app/src/Api/Resolver/OwnerScopedServiceResolver.php`
- Create: `packages/web-app/tests/Api/Resolver/OwnerScopedServiceResolverTest.php`
- Delete: `packages/web-app/src/Api/Resolver/ArchiveServiceResolver.php`
- Delete: `packages/web-app/src/Api/Resolver/ArchiveListServiceResolver.php`
- Delete: `packages/web-app/tests/Api/Resolver/ArchiveServiceResolverTest.php`
- Delete: `packages/web-app/tests/Api/Resolver/ArchiveListServiceResolverTest.php`
- Modify: `packages/web-app/src/Api/Controller/CreateArchiveController.php` (method docblock of `create`)
- Modify: `packages/web-app/src/Api/Controller/ListArchivesController.php` (method docblock of `list`)
- Modify: `packages/web-app/doc/features/0002-server/design/OwnerScopedService.md`
- Modify: `packages/web-app/doc/features/0002-server/feature.md`
- Modify: `packages/web-app/doc/features/0002-server/research/Owner-scoped-service.md`
- Modify: `packages/web-app/doc/features/0004-archive-list/design/ArchiveList.md` (the resolver sentence in Services)
- Modify: `packages/web-app/doc/flows/create-archive.md`

**Interfaces:**
- Consumes: `OwnerProvider::owner(): Owner`, `ArchiveServiceFactory::create(Owner $owner): ArchiveService`, `ArchiveListServiceFactory::create(Owner $owner): ArchiveListService`.
- Produces: `final class OwnerScopedServiceResolver implements ValueResolverInterface`. Constructor `(OwnerProvider $owners, ArchiveServiceFactory $archives, ArchiveListServiceFactory $lists)`. `resolve(Request $request, ArgumentMetadata $argument): array` with `@return list<ArchiveService|ArchiveListService>`.

- [ ] **Step 1: Write the failing test**

Create `tests/Api/Resolver/OwnerScopedServiceResolverTest.php`. Class `Yumo\Eleanor\Tests\Api\Resolver\OwnerScopedServiceResolverTest`. `setUp` builds `ArchiveServiceFactory` the way `ArchiveServiceResolverTest` does (`FakeArticleFetcher` returning `PipelineFixtures::article()` for `https://example.com/a`, `InMemoryArchiveStoreFactory`, `FakeArchiveLockFactory`, `NullLogger`) and `$this->lists = new ArchiveListServiceFactory(new InMemoryArchiveStoreFactory(new InMemoryArchiveShelf()), new NullLogger())`. Helper `argument(?string $type): ArgumentMetadata` is `new ArgumentMetadata('argument', $type, false, false, null)`. Helper `resolverFor(string $email, ?ArchiveListServiceFactory $lists = null)` passes `SwitchableOwnerProvider` and the setUp factories, using `$lists` when it is not null.

Tests:

- `test_builds_the_archive_service_for_the_owner_of_the_provider`: same two `archive(new UrlPointer('https://example.com/a'))` calls as `ArchiveServiceResolverTest`. Assert `ArchiveOutcome::Written`, then `ArchiveOutcome::AlreadyStored`.
- `test_asks_the_provider_on_every_archive_call_and_keeps_no_service`: switch `SwitchableOwnerProvider` from `ada@example.com` to `bob@example.com`. Both results are `ArchiveOutcome::Written`.
- `test_returns_the_list_service_of_the_current_owner`: same shelf, save, and query as `ArchiveListServiceResolverTest` (`ArchivePageQuery::from(null, null, null, null, 100)`, URL `https://example.com/a`). Ada's page total is `1` and the item URL is that string. After the provider switches to `bea@example.com`, total is `0` and items are `[]`.
- `test_returns_one_service_for_each_known_type`: an `ArchiveService` argument returns one `ArchiveService`. An `ArchiveListService` argument returns one `ArchiveListService`.
- `test_answers_a_nullable_argument_and_an_argument_with_a_default`: for each of `ArchiveService::class` and `ArchiveListService::class`, `new ArgumentMetadata('archives', $type, false, false, null, true)` and `new ArgumentMetadata('archives', $type, false, true, null, true)` each return one value.
- `test_lets_an_owner_failure_escape_before_any_service_is_built`: an `OwnerProvider` whose `owner()` throws `RuntimeException` with message `No owner.` Resolve `ArchiveService` and `ArchiveListService` on separate calls. Each call throws that exception. `expectException` cannot see both, so catch each and fail when a call returns.
- `test_ignores_an_argument_it_does_not_own_without_asking_the_owner`: the same throwing provider. `CreateArchiveBody::class`, `ListArchivesQuery::class`, and a null type each return `[]`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./bin/run test -- --filter OwnerScopedServiceResolverTest`

Expected: FAIL. Class `OwnerScopedServiceResolver` is not found.

- [ ] **Step 3: Implement `OwnerScopedServiceResolver`**

File `src/Api/Resolver/OwnerScopedServiceResolver.php`. Namespace `Yumo\Eleanor\Api\Resolver`.

`resolve` is this match and nothing else:

```php
return match ($argument->getType()) {
    ArchiveService::class => [$this->archives->create($this->owners->owner())],
    ArchiveListService::class => [$this->lists->create($this->owners->owner())],
    default => [],
};
```

Class docblock: builds the current owner's service for an action argument of type `ArchiveService` or `ArchiveListService`. A controller declares the service and does not build it. Any other argument type is left untouched.

Constructor docblock: creates the resolver from the current owner and the factories of the owner-scoped services. Property docs: `$owners` is the source of the owner of the current call. `$archives` builds the archive service of one owner. `$lists` builds the list service of one owner.

Method docblock: returns the service of the current owner when the argument type is one it knows, and nothing otherwise. `owner()` runs only for a matching type. `@return list<ArchiveService|ArchiveListService>`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `./bin/run test -- --filter OwnerScopedServiceResolverTest`

Expected: PASS. The old resolver tests still pass. Do not delete them in this step.

- [ ] **Step 5: Remove the two old resolvers**

Delete the four files listed above. In `CreateArchiveController::create`, the docblock sentence becomes `` `OwnerScopedServiceResolver` supplies `$archives` for the current owner. `` In `ListArchivesController::list`, the resolver sentence becomes `` `ListArchivesQueryResolver` supplies `$query`. `OwnerScopedServiceResolver` supplies `$archives`. `` Leave the `HEAD` sentence.

- [ ] **Step 6: Run the resolver test and the two route tests**

Run: `./bin/run test -- --filter 'OwnerScopedServiceResolverTest|CreateArchiveRouteTest|ListArchivesRouteTest'`

Expected: PASS. A failure here means the kernel still expects a deleted class, or both routes lost their service argument.

- [ ] **Step 7: Check registration**

Run: `./bin/docker-run php bin/console debug:container debug.argument_resolver.inner`

Expected: `OwnerScopedServiceResolver` is listed once. `ArchiveServiceResolver` and `ArchiveListServiceResolver` are absent. It is below `RequestAttributeValueResolver` (priority 100) and above the service resolver (priority -50). If it is above `request_attribute`, set `#[AsTaggedItem(priority: 0)]` on the class and add that priority to the design in step 9.

- [ ] **Step 8: Run quality and the full web-app suite**

Run: `./bin/run quality`

Expected: exit 0.

Run: `./bin/run test`

Expected: exit 0.

- [ ] **Step 9: Write the revision into the living docs**

`OwnerScopedService.md`:

- Replace the `ArchiveServiceResolver` module bullets with `OwnerScopedServiceResolver`, the constructor and `resolve` contract from this task's Interfaces, and the rule that `owner()` runs only on a match. State that both services stay `#[Exclude]`, the resolver stays global, and a later owner-scoped service adds one constructor argument and one `match` arm.
- Delete "When a second owner-scoped service exists, extract the shared step. Not before."
- Data flow step 2 names `OwnerScopedServiceResolver`.
- The test section names `OwnerScopedServiceResolverTest` and the seven test names from step 1.
- In Out of scope, replace "A generic resolver" with "A factory port and an iterable of factories."

`feature.md`: in the slice 5b prose, say the two calls moved into a value resolver, and that on 2026-11-01 `OwnerScopedServiceResolver` also builds `ArchiveListService`. In the Application code cell, the builder is `Yumo\Eleanor\Api\Resolver\OwnerScopedServiceResolver`, and the same resolver builds `ArchiveListService` from `ArchiveListServiceFactory`.

`research/Owner-scoped-service.md`, under "Resolved by slice 5b": the second service arrived with the archive list. `OwnerScopedServiceResolver` is that extracted step. The factories stay concrete.

`ArchiveList.md` Services paragraph: the controller does not build the list service. `OwnerScopedServiceResolver` returns `$lists->create($owner)` for an `ArchiveListService` argument and `$archives->create($owner)` for an `ArchiveService` argument.

`doc/flows/create-archive.md`: the diagram label and the box name are `OwnerScopedServiceResolver`. The file link is `OwnerScopedServiceResolver.php`. The role stays "Builds `ArchiveService` for the authenticated owner."

- [ ] **Step 10: Commit**

```bash
git add packages/web-app/src/Api/Resolver packages/web-app/src/Api/Controller/CreateArchiveController.php packages/web-app/src/Api/Controller/ListArchivesController.php packages/web-app/tests/Api/Resolver packages/web-app/doc/features/0002-server/design/OwnerScopedService.md packages/web-app/doc/features/0002-server/feature.md packages/web-app/doc/features/0002-server/research/Owner-scoped-service.md packages/web-app/doc/features/0004-archive-list/design/ArchiveList.md packages/web-app/doc/flows/create-archive.md packages/web-app/doc/features/0002-server/plans/2026-11-01-owner-scoped-service-resolver.md
git commit -m "$(cat <<'EOF'
refactor: build every owner-scoped service in one resolver

The second service had copied the owner-then-factory step into a second class.

EOF
)"
```

Do not add `services.yaml`. Do not add a factory interface.
