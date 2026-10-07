# Archive controller

`POST /api/archives` is one class. The method is `__invoke`. The class name carries the verb. The PHP namespace is still unset and is chosen with the first class.

```php
#[Route('/api/archives', methods: ['POST'])]
public function __invoke(…): JsonResponse
```

The attribute is the native PHP attribute. Symfony 7 and 8 do not read a docblock `@Route`.

Another route is another class. Shared code lives in services, or in a language construct when that construct fits.

## Three roles

The controller is the HTTP adapter. The firewall has already authenticated the caller. `__invoke` receives the body DTO from `#[MapRequestPayload]`, reads the URL, and passes that URL to the application service. The DTO does not enter the service. The controller does not call the orchestrator and does not build the pipeline.

The application service is a concrete class. The controller receives it in the constructor. There is no interface until a second implementation exists. The service calls `Orchestrator::fetchArticle`. `UrlGuard` runs inside that call. The service returns a result with no status, headers, or JSON.

The orchestrator is the library entry. It receives a URL. It returns `PipelineSuccess` or `PipelineNoContent`, or throws `OrchestratorException`.

A CLI adapter on another framework calls the same service with a URL and reads the same result.

## Response

`__invoke` maps the service result to a response DTO. Serialization attributes live on that DTO. The serializer belongs to Eleanor. It takes the DTO and returns a JSON string. It does not implement Symfony's serializer interface.

```php
$json = $serializer->serialize($responseDto);

return JsonResponse::fromJsonString($json);
```

The controller does not extend `AbstractController`. The serializer arrives through the constructor. `fromJsonString` accepts the HTTP status as its second argument. That status is recorded in [Flow.md](Flow.md).

A listener that serializes a returned object on its own waits until actions repeat this call and do nothing else.

## Still open

The URL field name, the error document, and the shape of the serializer attributes are still open. The HTTP status of each outcome, including 400 for an invalid body, is recorded in [Flow.md](Flow.md). The body of that error is part of the error document.

The reading of the Symfony docs is in [Symfony-controller.md](Symfony-controller.md). It is not this decision.
