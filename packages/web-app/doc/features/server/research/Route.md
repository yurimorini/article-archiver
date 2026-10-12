# Archive route

`POST /api/archives`

One call names one page URL. The first stored archive for that URL is a create. A later POST of the same URL is recorded in [Types.md](Types.md). The URL travels in the JSON body, not in the path, in the property `url`. The path has no trailing slash.

`/api` is the JSON prefix. A route under `/api` returns JSON. HTML pages, when they exist, live outside that prefix.

The path has no version segment. Evolution is the preference until an incompatible change is needed.

The request and the success response use `application/json`. The route does not negotiate a media type.

The error document is recorded in [Types.md](Types.md). CORS, HTTP caching, and CSRF stay open. The status of each outcome is recorded in [Flow.md](Flow.md).

Archive, article, and URL pointer are defined in the package [GLOSSARY.md](../../../GLOSSARY.md).

The sections below are research notes, not a decision.

## Symfony 8.1 routing research

This is a reading of the Symfony documentation on symfony.com, plus the 8.1.8 component source where a doc sentence does not fix the join or the HTTP status. On 12 October 2026, `https://symfony.com/doc/8.1/routing.html` responds 302 to `/doc/current/routing.html`. The pages below were read at `/doc/current/`. The application lock has `symfony/framework-bundle`, `symfony/routing`, `symfony/http-kernel`, and `symfony/http-foundation` at v8.1.8.

The query string is not part of the match. [Routing](https://symfony.com/doc/current/routing.html), "Creating Routes as Attributes".

## Path prefix



### Class-level `#[Route]`

- Mechanism: one `#[Route]` on the controller class holds shared configuration. Each method that has its own `#[Route]` is registered with that shared configuration. [Routing](https://symfony.com/doc/current/routing.html), "Route Groups and Prefixes".
- What it concatenates: the class path string is placed in front of the method path string, and the class `name` is placed in front of the method name. The documented result is class path `/blog` plus method path `/{_locale}` equals `/blog/{_locale}`, and class name `blog_` plus method name `index` equals `blog_index`. The same heading says both routes also keep the class `_locale` requirement. [AttributeClassLoader::addRoute](https://github.com/symfony/routing/blob/v8.1.8/Loader/AttributeClassLoader.php) performs that join as `$prefix.$path` and `$globals['name'].$name`, then [Route::setPath](https://github.com/symfony/routing/blob/v8.1.8/Route.php) stores `'/'` plus the pattern with leading slashes removed. A trailing slash on the class path stays in the middle when the method path also starts with `/`: `/api/` plus `/archives` is stored as `/api//archives`. A trailing slash on the method path stays at the end. The same `setPath` does not insert a slash when the method path has none: `/api` plus `archives` is `/apiarchives`.
- Empty method path: method path `/` joined to class path `/blog` is `/blog/`. Method path `''` joined to `/blog` is `/blog`. `trailing_slash_on_root` is not available on PHP attributes. [Routing](https://symfony.com/doc/current/routing.html), "Route Groups and Prefixes". The attribute loader does not call the import prefix helper.
- A class path that is an array is a per-locale prefix. The loader concatenates each locale prefix with the method path and sets `_locale`. [AttributeClassLoader::getGlobals](https://github.com/symfony/routing/blob/v8.1.8/Loader/AttributeClassLoader.php) and `addRoute`. The routing page shows that array form on an import, and shows an attribute `path` array as a full path per locale. [Routing](https://symfony.com/doc/current/routing.html), "Localized Routes (i18n)".
- What it does not do: it does not register a method that has no `#[Route]`. When the class is invokable, has no method routes, and the only `#[Route]` is on the class, the loader resets the shared prefix and uses the class attribute as the route itself. [AttributeClassLoader::load](https://github.com/symfony/routing/blob/v8.1.8/Loader/AttributeClassLoader.php). It does not deduplicate a method path that already begins with the same segment. It does not set the response `Content-Type`. Class `methods` are merged with the method attribute; class `requirements` are replaced by the method requirement of the same name; a method `condition` replaces the class condition. Same `addRoute` method.



### Method path that already includes `/api`

- Mechanism: the path on the action is the pattern that matches. `#[Route('/blog', name: 'blog_list')]` matches `/blog`. [Routing](https://symfony.com/doc/current/routing.html), "Creating Routes as Attributes". The same is `path: /blog` in a YAML or PHP route file. "Creating Routes in YAML or PHP Files".
- What it concatenates: nothing by itself. A class `#[Route]` path, or an import `prefix`, is still applied in front of this path by the mechanisms above and below.
- Trailing slash: [Route::setPath](https://github.com/symfony/routing/blob/v8.1.8/Route.php) keeps one trailing slash and rejects extra slashes only at the start of the pattern. The finished path still follows the GET/HEAD redirect in "Trailing slash on the finished path" below.
- What it does not do: it does not mark the route as JSON, and it does not read `Content-Type`.



### Import `prefix`

- Mechanism: a route resource import takes `prefix`. The routing page says this value "is added to the beginning of all imported route URLs". The example `prefix: '/blog'` turns the imported paths into `/blog/{_locale}` and `/blog/{_locale}/posts/{slug}`. YAML and PHP imports both do this. [Routing](https://symfony.com/doc/current/routing.html), "Route Groups and Prefixes".
- What it concatenates: [ContentLoaderTrait::parseImport](https://github.com/symfony/routing/blob/v8.1.8/Loader/ContentLoaderTrait.php) calls [PrefixTrait::addPrefix](https://github.com/symfony/routing/blob/v8.1.8/Loader/Configurator/Traits/PrefixTrait.php), which calls [RouteCollection::addPrefix](https://github.com/symfony/routing/blob/v8.1.8/RouteCollection.php). That method trims `/` from both ends of the prefix, then sets the path to `'/'` plus the trimmed prefix plus the route's current path. `/api`, `/api/`, and `api` all produce the same prefix text. The route path already starts with `/`, so the join is `/api` plus `/archives` equals `/api/archives`. A trailing slash that belongs to the imported route path remains.
- Empty imported path: the same heading says an empty path prefixed with `/blog` becomes `/blog/`. `trailing_slash_on_root: false` stores that root as `/blog` instead. The flag defaults to true. The `trailingSlashOnRoot` argument of `CollectionConfigurator::prefix()` was added in Symfony 8.1. Same heading, and the 8.1 note under it. [PrefixTrait::addPrefix](https://github.com/symfony/routing/blob/v8.1.8/Loader/Configurator/Traits/PrefixTrait.php) rewrites only the route whose path is exactly the prefixed root.
- A prefix that trims to empty (`''` or `/`) leaves every imported path unchanged. [RouteCollection::addPrefix](https://github.com/symfony/routing/blob/v8.1.8/RouteCollection.php).
- What it does not do: it does not change route names (`name_prefix` does that). It does not apply to routes loaded outside that import. It does not set a format or a response `Content-Type`. On the same import, `methods` replaces the imported methods, and `condition` replaces an existing condition. [RouteCollection::setMethods](https://github.com/symfony/routing/blob/v8.1.8/RouteCollection.php) and `setCondition`.



### `name_prefix`

- Mechanism: `name_prefix` on an import "is added to the beginning of all imported route names". The example `blog_` turns `index` into `blog_index`. [Routing](https://symfony.com/doc/current/routing.html), "Route Groups and Prefixes".
- What it concatenates: the string is placed in front of each route name and of alias names that point inside the collection. No slash and no extra separator are inserted. [RouteCollection::addNamePrefix](https://github.com/symfony/routing/blob/v8.1.8/RouteCollection.php).
- What it does not do: it does not change any URL. A trailing slash in the name prefix is a character in the name, not a path rule.



### Localized prefix

- Mechanism: an import `prefix` may be a map of locale to prefix. The documented map is `en: ''` and `nl: '/nl'`. [Routing](https://symfony.com/doc/current/routing.html), "Localized Routes (i18n)".
- What it concatenates: each locale prefix is trimmed the same way as a string prefix, then placed in front of the route path. An empty prefix leaves the path as it was and still sets `_locale` on that copy. A route that already has `_locale` is imported only for that locale; a missing locale prefix for that route is an error. The same heading states the locale limit. [PrefixTrait::addPrefix](https://github.com/symfony/routing/blob/v8.1.8/Loader/Configurator/Traits/PrefixTrait.php). `trailing_slash_on_root: false` drops the slash only when the imported path is `/`.
- A separate mechanism is a localized route path: the route `path` itself is a map of locale to full path (`en: /about-us`, `nl: /over-ons`). That map is the whole path, not a prefix added to another path. Same heading. On an attribute, that map uses the `path` named argument.
- What it does not do: a localized prefix does not translate the remainder of the path, and it does not read `Content-Type`.



### `framework.router`

- Mechanism: [Framework Configuration Reference](https://symfony.com/doc/current/reference/configuration/framework.html), "router". The children are `default_uri`, `enabled`, `http_port`, `https_port`, `resource`, `strict_requirements`, `type`, and `utf8`.
- What it matches: `resource` is the path of the main routing file. `default_uri` is "the default URI used to generate URLs in a non-HTTP context". `http_port` and `https_port` are used when matching the scheme. `utf8` runs requirement regular expressions with the UTF-8 modifier. `strict_requirements` changes URL generation when parameters miss their requirements. Same headings under "router".
- What it does not do: this reference lists no path prefix. A URL prefix is the import `prefix` or a class `#[Route]` path, not a `framework.router` key.



### Trailing slash on the finished path

- Mechanism: after the path is built, Symfony redirects between the slash form and the no-slash form for `GET` and `HEAD` only. A route URL `/foo` matches `/foo` and answers 301 to `/foo` when the request is `/foo/`. A route URL `/foo/` does the opposite. [Routing](https://symfony.com/doc/current/routing.html), "Redirecting URLs with Trailing Slashes".
- What it does not do: this redirect is not the import-prefix trim, and it does not run for other HTTP methods.



## JSON media type

No route option both requires the request `Content-Type` to be `application/json` and sets the response `Content-Type` to `application/json`. The matchers below split the URL, the HTTP method, the request format, and the request header.

### `methods`

- Mechanism: a route matches every HTTP verb until `methods` restricts the list. The example is `#[Route('/api/posts/{id}', methods: ['GET', 'HEAD'])]`. [Routing](https://symfony.com/doc/current/routing.html), "Matching HTTP Methods". An empty list means any method. [Route::setMethods](https://github.com/symfony/routing/blob/v8.1.8/Route.php).
- What it matches: the request method.
- What it does not do: it does not read `Content-Type` and it does not set the response `Content-Type`. A path match with a disallowed method becomes `MethodNotAllowedHttpException`, status 405. [RouterListener::onKernelRequest](https://github.com/symfony/http-kernel/blob/v8.1.8/EventListener/RouterListener.php) and [MethodNotAllowedHttpException](https://github.com/symfony/http-kernel/blob/v8.1.8/Exception/MethodNotAllowedHttpException.php).



### `format` and `_format`

- Mechanism: `_format` is a special route parameter. "The matched value is used to set the request format of the `Request` object. This is used for such things as setting the `Content-Type` of the response (e.g. a `json` format translates into a `Content-Type` of `application/json`)." The shortcut of the same name without the underscore is `format`. The documented attribute is `format: 'html'` on a path that also contains `.{_format}`, with a requirement `_format: html|xml`. [Routing](https://symfony.com/doc/current/routing.html), "Special Parameters".
- What it matches: `format` writes the default `_format`. [Route attribute](https://github.com/symfony/routing/blob/v8.1.8/Attribute/Route.php) and [RouteTrait::format](https://github.com/symfony/routing/blob/v8.1.8/Loader/Configurator/Traits/RouteTrait.php). When the path contains `{_format}`, that placeholder is matched from the URL. A default lets the route match when that parameter is absent from the URL. [Routing](https://symfony.com/doc/current/routing.html), "Optional Parameters". When the path does not contain `{_format}`, the default still supplies the request format and does not add a suffix to the URL.
- Request format: [Request::getRequestFormat](https://github.com/symfony/http-foundation/blob/v8.1.8/Request.php) uses a value set by `setRequestFormat()`, otherwise the `_format` request attribute, otherwise `html`. That method does not read the `Content-Type` header.
- Response `Content-Type`: [Framework Configuration Reference](https://symfony.com/doc/current/reference/configuration/framework.html), "formats", says Symfony uses the format-to-mime map to set the response `Content-Type` when the application does not set one. The first mime type for the format is the one used. The built-in `json` format is `application/json`, then `application/x-json`. [Request::initializeFormats](https://github.com/symfony/http-foundation/blob/v8.1.8/Request.php). [Response::prepare](https://github.com/symfony/http-foundation/blob/v8.1.8/Response.php) sets that header only when the response has none, from `getRequestFormat(null)`. If that call returns null, `prepare` sets `text/html`. A response that already has the header, including `JsonResponse`, keeps its own value. [The HttpFoundation Component](https://symfony.com/doc/current/components/http_foundation.html), "Creating a JSON Response": `JsonResponse` sets `Content-Type` to `application/json`.
- What it does not do: `format: 'json'` and `_format` do not require the request `Content-Type` to be `application/json`. The header reader is `Request::getContentTypeFormat()`, a different method. Same `Request` class.



### `condition`

- Mechanism: `condition` is an expression that must be true for the route to match. The expression can use `context` (`RequestContext`), `request` (the Symfony `Request`), and `params` (matched route parameters). The documented header check is `request.headers.get('User-Agent')`. [Routing](https://symfony.com/doc/current/routing.html), "Matching Expressions".
- What it matches: whatever the expression returns. The same variables can read `request.headers.get('content-type')`. The page does not show a `Content-Type` expression.
- What it does not do: a condition is ignored when generating URLs. Same heading. A failed condition is a failed match. If no route matches, [RouterListener::onKernelRequest](https://github.com/symfony/http-kernel/blob/v8.1.8/EventListener/RouterListener.php) throws `NotFoundHttpException`, status 404. [NotFoundHttpException](https://github.com/symfony/http-kernel/blob/v8.1.8/Exception/NotFoundHttpException.php). The router does not answer 415 for a failed condition. The condition does not set the response `Content-Type`.



### `requirements`

- Mechanism: `requirements` are regular expressions for route parameters, so two routes that share a shape can be told apart. The example requires `{page}` to match `[0-9]+`. [Routing](https://symfony.com/doc/current/routing.html), "Parameters Validation". The same map can constrain `{_format}` in the path, as in "Special Parameters".
- What it matches: the text captured for that parameter in the path or the host.
- What it does not do: a requirement does not read request headers. A requirement of `json` on `{_format}` constrains the URL suffix, not `Content-Type`.



### `defaults`

- Mechanism: `defaults` fill parameters that are missing from the URL, and can pass extra controller arguments that are not in the path. [Routing](https://symfony.com/doc/current/routing.html), "Optional Parameters" and "Extra Parameters". `format` and `locale` are shortcuts that store `_format` and `_locale` as defaults. "Special Parameters".
- What it matches: nothing in the request header. A default supplies a value when the URL omits the parameter.
- What it does not do: a default `_format` of `json` does not require `Content-Type: application/json`.



### `stateless`

- Mechanism: `stateless: true` declares that the session should not be used when the route matches. If the session is used, `kernel.debug` true throws `UnexpectedSessionUsageException`, and `kernel.debug` false logs a warning. [Routing](https://symfony.com/doc/current/routing.html), "Stateless Routes". The attribute stores the default `_stateless`. [Route attribute](https://github.com/symfony/routing/blob/v8.1.8/Attribute/Route.php).
- What it matches: session use during the request.
- What it does not do: it does not match a path, a method, or `Content-Type`, and it does not set the response `Content-Type`.



### Rejecting a request whose `Content-Type` is not `application/json`

The router matchers above do not emit 415. Status 415 is a later kernel or controller concern. Two documented request-format names stay distinct: `getRequestFormat()` reads `_format`, and `getContentTypeFormat()` reads the `Content-Type` header. [Request::getContentTypeFormat](https://github.com/symfony/http-foundation/blob/v8.1.8/Request.php) calls `getFormat()` on that header. `getFormat()` matches the full mime type, then the type before the first `;`, so `application/json; charset=utf-8` is the `json` format. Same method.

- `#[MapRequestPayload(acceptFormat: 'json')]` declares the payload formats the mapper accepts. The controller page also documents validation failure as 422. [Controller](https://symfony.com/doc/current/controller.html), "Mapping Request Payload". That heading does not state the status for a rejected `Content-Type`. In http-kernel 8.1.8 the mapper runs on `kernel.controller_arguments`, after the route has matched. It reads `getContentTypeFormat()`. A missing format, a format outside `acceptFormat`, or a format the serializer cannot decode throws `UnsupportedMediaTypeHttpException`, status 415. A body that is not valid for that format throws `BadRequestHttpException`, status 400. [RequestPayloadValueResolver::mapRequestPayload](https://github.com/symfony/http-kernel/blob/v8.1.8/Controller/ArgumentResolver/RequestPayloadValueResolver.php) and [UnsupportedMediaTypeHttpException](https://github.com/symfony/http-kernel/blob/v8.1.8/Exception/UnsupportedMediaTypeHttpException.php). With `acceptFormat` omitted, an unmapped `Content-Type` still throws 415. This check does not set the response `Content-Type`.
- `#[Serialize]` sets the response from the request format (`getRequestFormat()`), defaulting to JSON, and sets `Content-Type` from that format. An unsupported format returns 415. [Controller](https://symfony.com/doc/current/controller.html), "Serializing Controller Return Values Automatically". That 415 is the response format the serializer cannot encode. The heading does not check the request `Content-Type`.
- The serializer cookbook shows a controller test: if `getContentTypeFormat()` is not `json`, throw `BadRequestException`. [How to Use the Serializer](https://symfony.com/doc/current/serializer.html), "Deserializing an Object". That reads the request `Content-Type`. It is application code, not a route option. `BadRequestException` implements `RequestExceptionInterface`, and [HttpKernel::handle](https://github.com/symfony/http-kernel/blob/v8.1.8/HttpKernel.php) wraps that interface in `BadRequestHttpException`, status 400. [BadRequestHttpException](https://github.com/symfony/http-kernel/blob/v8.1.8/Exception/BadRequestHttpException.php). The cookbook heading does not mention 415.
- `#[Route(..., format: 'json')]` on a JSON API is documented so validation errors are JSON rather than HTML. [Controller](https://symfony.com/doc/current/controller.html), "Mapping Request Payload". That is the request format used for the error body. It does not require the request `Content-Type`.

