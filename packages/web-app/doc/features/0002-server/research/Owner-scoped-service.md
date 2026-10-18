# Owner-scoped service in controllers

Not a decision. A direction until a spec adopts it.

## Question

`CreateArchiveController` builds its own service. It takes `OwnerProvider` and `ArchiveServiceFactory` in the constructor. `create()` asks the provider for the `Owner`, asks the factory for the `ArchiveService`, then calls `archive($url)`.

Every later controller that works on one owner's data needs the same two steps. How can a controller receive the owner-scoped service without repeating them?

## What exists today

- `OwnerProvider::owner()` returns the `Owner` of the current call. `ConfiguredOwnerProvider` reads the configured email. The firewall slice replaces it with a provider that reads the authenticated caller. See the revised bullet in [feature.md](../feature.md) (slice merge, 2026-10-18).
- `ArchiveServiceFactory::create(Owner)` returns an `ArchiveService` bound to that owner's store. The factory holds no state.
- `LogApiRequest` listens to `kernel.controller`, builds an `EnrichedRequest`, and stores it in `request->attributes`.
- [Controller.md](Controller.md) says the controller receives the application service in the constructor. The [Archive command](../specs/ArchiveCommand.md) spec locks the controller asking the provider and calling the factory. Both would be revised by any option below.

## There is no middleware that injects into a controller

Symfony has no middleware layer. Three mechanisms can run work before the controller.

### 1. Subscriber on `kernel.controller` plus a request attribute

- A subscriber resolves the owner, calls the factory, and stores the service in `request->attributes`.
- This is the pattern `LogApiRequest` already uses for `EnrichedRequest`.
- The controller reads an untyped attribute. The link between subscriber and controller is a string key.
- It runs for every route, including routes that do not need the service.
- Fits cross-cutting request facts. Does not fit a dependency of a controller.

### 2. Container service built from `RequestStack`

- The container builds the owner-scoped service per request.
- It puts per-request state in the container. That is fragile with long-lived runtimes and in tests.
- Not recommended.

### 3. Argument value resolver

- A class that implements `ValueResolverInterface`. Its `resolve(Request, ArgumentMetadata)` returns an empty array when it cannot resolve the argument, or an array with the value.
- The resolver checks the argument type, for example `ArchiveService`. It asks `OwnerProvider` for the owner and `ArchiveServiceFactory` for the service, and returns it.
- The controller declares `ArchiveService $archives` as an action argument. Its constructor loses both dependencies.
- It runs only for controllers that declare that argument. The type stays strong.
- A unit test of the action passes a fake service. No HTTP and no owner are involved.
- When the firewall slice changes `OwnerProvider`, the resolver and the controllers stay as they are.
- If the owner cannot be resolved, the failure is raised in the resolver before the action. One place can map it to a 401.

## Facts from the Symfony docs

Source: [Extending Action Argument Resolving](https://symfony.com/doc/current/controller/value_resolver.html).

- A class that implements `ValueResolverInterface` gets the tag `controller.argument_value_resolver` automatically. `resolve()` is called for each controller argument.
- Resolvers run in priority order until one provides a value. The built-in `RequestAttributeValueResolver` has priority `100`. A resolver that does not read request attributes should use a priority below `100`.
- `ServiceValueResolver` injects a service when the argument type is a valid service class or interface. If `ArchiveService` is also a container service, order decides which resolver wins. Check the order with `php bin/console debug:container debug.argument_resolver.inner`.
- A resolver can be targeted instead of global. `#[AsTargetedValueResolver('name')]` on the resolver plus `#[ValueResolver('name')]` on the argument means it runs only when named. This avoids any clash with `ServiceValueResolver`.
- `RequestPayloadValueResolver` is already targeted. It runs only with `#[MapRequestPayload]` or `#[MapQueryString]`. The existing controller uses this pattern for the body DTO.

Checked in slice 5b: `ServiceValueResolver` has priority `-50` and `DefaultValueResolver` has `-100`, in `vendor/symfony/framework-bundle/Resources/config/web.php`. With `debug:container debug.argument_resolver.inner`, `ArchiveServiceResolver` (default priority 0) runs after `request_attribute` and before `service`. `ArchiveService` is `#[Exclude]`, so `ServiceValueResolver` never competes for it. An argument that is nullable or has a default is still answered by the resolver, so the default is never used. `ArchiveServiceResolverTest` pins this.

## Context object or service per type

- A context object such as `ApiContext` holds the service, the owner, and the request id. The controller receives one argument.
- It tends to become a grab-bag. Every controller receives what it does not use. It overlaps responsibilities.
- A resolver per service type grows with each new owner-scoped service.
- A context object is worth it only if most controllers use the same three or four items together.

## Direction

- One resolver per owner-scoped service type, starting with `ArchiveService`.
- When a second owner-scoped service exists, extract the shared "owner, then factory" step. A common interface for owner-scoped factories can drive one generic resolver. Not before the second service.
- `OwnerProvider` and `ArchiveServiceFactory` stay as they are. Only the caller changes.

## Order of execution

- Resolvers run after `kernel.request`, so after the firewall. The authenticated owner will be available.
- They run after `kernel.controller`, so after `LogApiRequest` has stored `EnrichedRequest`.

## Resolved by slice 5b

[OwnerScopedService.md](../specs/OwnerScopedService.md) adopts the resolver option.

- Global resolver, not targeted. `ArchiveService` stays `#[Exclude]`. Only the factory is a service.
- Own slice 5b, not part of slice 8.
- The revised choices in [Controller.md](Controller.md) and in the [Archive command](../specs/ArchiveCommand.md) spec were revised with the user's agreement.

## Still open

- How the unresolved-owner failure maps to a status. Slice 6 (Problem document) and slice 8 (Firewall) own it.
