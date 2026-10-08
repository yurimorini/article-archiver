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

The firewall's user is the framework's type. A use case that has a rule about the person receives an object of Eleanor's, built at the edge from that framework user. The mapping is written when the rule exists. A mechanism that only the framework runs, such as its rate limiter, keeps the framework type. [Logging.md](Logging.md) records that split.

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

## Closed by the types session

The URL field, the error document, and the serializer attributes are recorded in [Types.md](Types.md). The HTTP status of each outcome, including 400 for an invalid body and 200 when the archive is already stored, is recorded in [Flow.md](Flow.md).

The reading of the Symfony docs is in [Symfony-controller.md](Symfony-controller.md). It is not this decision.
