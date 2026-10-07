# Symfony controller research notes

This is a reading of the Symfony documentation on symfony.com, not a decision. On 7 October 2026 the docs version menu labels 8.1 as current, lists 7.4 and 8.0, and lists 8.2 as dev. `/doc/8.1/` redirects to `/doc/current/`. The pages below were read for 7.4, 8.0, and 8.1.

The action shape matches in 7.4 and 8.0. The 7.4 routing page still documents XML route files; 8.0 and 8.1 document YAML or PHP files. Symfony 8.1 adds `#[Serialize]` and extra `MapRequestPayload` options. Attribute names, and the order "resolve arguments, then call the action," stay the same.

## Where the route is declared

The controller chapter's example is a PHP attribute on the action, class `Symfony\Component\Routing\Attribute\Route`: `#[Route('/lucky/number/{max}', name: 'app_lucky_number')]`. [Controller](https://symfony.com/doc/7.4/controller.html), "A Basic Controller" and "Mapping a URL to a Controller". [Routing](https://symfony.com/doc/7.4/routing.html), "Creating Routes as Attributes": attributes sit next to the controller and are on by default in a Flex app. The example is `#[Route('/blog', name: 'blog_list')]` on `list()`. On [8.0](https://symfony.com/doc/8.0/routing.html) and [8.1](https://symfony.com/doc/current/routing.html) that heading says the attribute wins over YAML or PHP file routes. The 7.4 sentence includes XML in that list.

"Creating Routes in YAML, XML or PHP Files" (7.4) is the file alternative. On 8.0 and 8.1 the heading is "Creating Routes in YAML or PHP Files".

"Matching HTTP Methods": a route matches any verb until `methods` restricts it (`#[Route('/api/posts/{id}', methods: ['GET', 'HEAD'])]`).

"Special Parameters": `_format` sets the request format and feeds the response `Content-Type` (json becomes `application/json`). The controller page writes this as `format: 'json'` on `#[Route]`.

## One method or several

[Controller](https://symfony.com/doc/7.4/controller.html), "A Basic Controller": a controller can be any PHP callable, and is usually a method on a class. The sample method is `number()`. "Matching HTTP Methods" shows `show()` and `edit()` on one class.

[How to Define Controllers as Services](https://symfony.com/doc/7.4/controller/service.html), "Invokable Controllers": a single `__invoke()` action is also supported, called a common ADR practice. `#[Route]` sits on the class. "Using the #[Route] Attribute" says `#[Route]` registers the class as a service. The basic-controller chapter is the named-method case.

## JSON body

[Controller](https://symfony.com/doc/7.4/controller.html), "Mapping Request Payload": for POST or PUT, `#[MapRequestPayload]` (`Symfony\Component\HttpKernel\Attribute\MapRequestPayload`) maps the payload onto a typed argument. The sample receives `UserDto`.

Validation failure returns 422 by default. `validationFailedStatusCode` changes it. `format: 'json'` makes that error handling emit JSON instead of HTML. The samples do not read the body.

[HttpKernel](https://symfony.com/doc/7.4/components/http_kernel.html), "4) Getting the Controller Arguments", then "5) Calling the Controller": the kernel resolves arguments, then calls the controller. The 422 status belongs to resolving that argument.

"The Request Object as a Controller Argument" is separate: the action type-hints `Request` and reads the request.

8.1 adds variadic `#[MapRequestPayload]` arguments, expression `validationGroups`, and, under "Mapping Empty Data", `mapWhenEmpty`. Those are absent on 7.4 and 8.0. All three state the 422 validation status. They do not state a status for a body that is not valid JSON.

## What the action returns

[Controller](https://symfony.com/doc/7.4/controller.html), "Returning JSON Response" (same on 8.0 and 8.1): `return $this->json(...)` returns a `JsonResponse` and sets the Content-Type. The serializer encodes when enabled; otherwise `json_encode` does. "The Base Controller Class & Services": extending `AbstractController` is optional and supplies helpers. The basic example skips it and returns `new Response`.

[Controller](https://symfony.com/doc/current/controller.html), "Serializing Controller Return Values Automatically" (8.1 only): `#[Serialize]` (`Symfony\Component\HttpKernel\Attribute\Serialize`) lets the action return an object or array. The framework serializes from the request format, default JSON, and sets Content-Type. An unsupported format gets 415. Serializer must be installed. 7.4 and 8.0 document a `JsonResponse`. 8.1 documents that and a framework-serialized value.

## How a service arrives

[Controller](https://symfony.com/doc/7.4/controller.html), "Fetching Services": a type-hint on an action argument is injected when the controller is a service. The same section says constructor injection works in controllers too.

[Service Container](https://symfony.com/doc/7.4/service_container.html), "Injecting Services/Config into a Service": the container passes a `__construct()` argument type-hinted with a service class or interface. The object does not construct that dependency.

[How to Define Controllers as Services](https://symfony.com/doc/7.4/controller/service.html), "Using the #[Route] Attribute": `#[Route]` registers the controller as a public service and enables argument injection.

## Not covered

- Security: firewalls and authenticators.
- Messenger: queues and message handlers.
- Forms: form types and form requests.
- Validation constraint lists: groups and the 422 status only.
- Serializer groups: `serializationContext` and the `#[Serialize]` context.
