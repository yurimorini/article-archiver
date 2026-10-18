# Owner-scoped service resolver

Module design for slice 5b. There is no contract and no ticket. The goal is recorded in [feature.md](../feature.md). The research is [Owner-scoped-service.md](../research/Owner-scoped-service.md). This spec adopts the resolver option of that note. This slice adds no glossary term and no ADR.

## Revises

[ArchiveCommand.md](ArchiveCommand.md) closed this: the controller takes `ArchiveServiceFactory` and `OwnerProvider` in its constructor, asks the provider for the `Owner`, and calls `create`. [Controller.md](../research/Controller.md) closed this: the application service arrives through the constructor. This slice replaces both. The controller receives the `ArchiveService` as an action argument. The `OwnerProvider` and `ArchiveServiceFactory` classes, the service, and the data flow after the service stay as they are. The edits to those two files are made when this spec is accepted.

## Why

Every controller that works on one owner's archives needs the same two steps: read the owner, build the service. A resolver does those two steps once. A controller declares the service it needs and does not build it.

## Module

One new class in `Yumo\Eleanor\Api\Resolver`.

- `ArchiveServiceResolver`: final class implementing `Symfony\Component\HttpKernel\Controller\ValueResolverInterface`.
  - The constructor takes `OwnerProvider` and `ArchiveServiceFactory`.
  - `resolve(Request $request, ArgumentMetadata $argument): iterable` returns an empty array unless `$argument->getType()` is `ArchiveService::class`. For that type it returns a one-item array: `$this->factory->create($this->owners->owner())`.
  - It does not read the request.
  - It is global, not targeted. `ArchiveService` is `#[Exclude]`, so `ServiceValueResolver` cannot resolve it and no other resolver competes for that type. No attribute is needed on the argument.

`CreateArchiveController`:

- No constructor.
- `create(CreateArchiveBody $body, ArchiveService $archives): JsonResponse`. The body argument keeps `MapRequestPayload`.
- `$result = $archives->archive($url)`. The status, the JSON, and the `ArchiveException` handling do not change.
- The class docblock keeps its note that `ArchiveException` is not caught.

When a second owner-scoped service exists, extract the shared step "owner, then factory" from the two resolvers. Not before.

## Configuration

No change to `config/services.yaml` or `config/services_test.yaml`. The `Yumo\Eleanor\` resource registers the resolver. A class that implements `ValueResolverInterface` receives the tag `controller.argument_value_resolver` automatically. `OwnerProvider` and `ArchiveServiceFactory` autowire as before.

The default priority is used. The built-in `RequestAttributeValueResolver` runs at priority 100 and reads attributes, which this resolver does not. The plan checks the order with `php bin/console debug:container debug.argument_resolver.inner`.

## Data flow

`POST /api/archives`, unchanged for the client:

1. `RequestPayloadValueResolver` maps and validates the body.
2. `ArchiveServiceResolver` asks `OwnerProvider` for the `Owner` and `ArchiveServiceFactory` for the service of that owner.
3. The action calls `$archives->archive($url)` and answers 201 or 200 as before.

The resolver runs after `kernel.request`, so after the firewall slice's authentication. It runs after `kernel.controller`, so after `LogApiRequest`.

## Errors

None added. An exception thrown by `OwnerProvider::owner()` escapes the resolver before the action runs. The current provider throws none. The firewall slice and the problem-document slice own the mapping of that case.

## Test

Unit test, no kernel, `tests/Api/Resolver/ArchiveServiceResolverTest.php`:

- An argument of type `ArchiveService` returns one `ArchiveService`. With the real in-memory factory and the fake fetcher, archiving a URL through it, then through a second resolved service for the same owner, gives `AlreadyStored`. This proves the owner reached the factory.
- An argument of another type returns an empty array.
- An argument with no type returns an empty array.

`OwnerProvider` in that test is a small stub that returns a chosen `Owner`.

No other test changes. `CreateArchiveRouteTest` and `LogApiRequestTest` stay as they are and prove the wiring through the kernel. `ProductionWiringTest` handles a real request on the `prod` container, so it also proves the resolver is registered there. A failing wiring would stop all three.

`./bin/quality` passing for `web-app` is an acceptance check, not a test.

## Documents

- [ArchiveCommand.md](ArchiveCommand.md): the controller bullet and the data flow step 2 point to this spec.
- [Controller.md](../research/Controller.md): the service arrives as an action argument.
- [feature.md](../feature.md): slice 5b row, "Application code", "Specs and plans", and a line in the slice merge note that records why the controller changed.
- No README change. The curl is the same.

## Out of scope

A generic resolver. A targeted resolver. A context object. Mapping an unresolved owner to a status. Any change to `ArchiveService`, `ArchiveServiceFactory`, `OwnerProvider`, or the store.
