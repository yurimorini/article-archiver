# Testing one HTTP route

This is a reading of the Symfony documentation on symfony.com, not a decision. On 12 October 2026 the docs version menu on the testing page labels 8.1 as current, lists 8.0 and 7.4 among the stable versions, and lists 8.2 as dev. `https://symfony.com/doc/8.1/testing.html` responds with a redirect to `/doc/current/testing.html`. The "Edit this page" link on that current page points at the `8.1` branch of `symfony-docs`. The pages below were read as that current 8.1 text.

The question is what official methods exist to show that `POST /api/archives` is invoked. Nothing here picks one.

## This repository

`packages/web-app/composer.json` `require-dev` lists `phpunit/phpunit` `^11`, `friendsofphp/php-cs-fixer`, and `phpstan/phpstan`. It does not list `symfony/browser-kit`, `symfony/panther`, or `symfony/http-client`.

`packages/web-app/composer.lock` has no installed package named `symfony/browser-kit`, `symfony/css-selector`, `symfony/dom-crawler`, `symfony/http-client`, or `symfony/panther`. The string `symfony/browser-kit` appears only in `require-dev` of `symfony/framework-bundle` v8.1.8 and `symfony/http-kernel` v8.1.8. Composer does not install another package's `require-dev`, so browser-kit is not a transitive dependency of this app.

The only test is `tests/KernelTest.php`. It extends PHPUnit `TestCase`, constructs `Yumo\Eleanor\Kernel`, and boots it. It does not extend `KernelTestCase` or `WebTestCase`. `phpunit.xml.dist` sets `APP_ENV` to `test` on both `<env>` and `<server>`, with `force="true"`.

The image in the repo-root `Dockerfile` is `php:8.5-cli-bookworm`. That file installs git, unzip, XML and curl libraries, Xdebug, and Composer. It does not install the Symfony CLI. `bin/docker-run` runs one container and does not pass a publish flag, so it does not open a host port. There is no Compose web service in this reading.

## What Symfony calls these tests

[Testing](https://symfony.com/doc/current/testing.html), "Types of Tests":

- A unit test checks one unit of code, such as a single class.
- An integration test checks a combination of classes and commonly uses the service container. It does not yet cover the fully working application.
- An application test, also called a functional test, checks a complete application. "They make HTTP requests (both real and simulated ones) and test that the response is as expected."

The same page, opening: Symfony integrates with PHPUnit. For PHPUnit itself it points at the [official PHPUnit documentation](https://docs.phpunit.de/) and, for writing a test class, at [Writing Tests for PHPUnit](https://docs.phpunit.de/en/13.1/writing-tests-for-phpunit.html). That linked page shows a `TestCase` with `assertSame()`. It does not document an HTTP client, a listening socket, or a web server. The only "server" on that page is a PostgreSQL server as an example of a test that cannot run in every environment.

## Booting the kernel, and `APP_ENV=test`

[Testing](https://symfony.com/doc/current/testing.html), "Integration Tests": `KernelTestCase` boots the kernel with `bootKernel()`. The kernel is rebooted for each test. The kernel class comes from the `KERNEL_CLASS` environment variable, which the page says is in the default `.env.test` from Flex (`KERNEL_CLASS=App\Kernel`). Overriding `getKernelClass()` or `createKernel()` takes precedence over that variable.

"Set Up Your Test Environment": "The tests create a kernel that runs in the `test` environment." Settings for that environment live in `config/packages/test/` or under `when@test`. `bootKernel()` accepts `environment` and `debug`. The page recommends `debug` set to `false` on CI because that improves performance and disables clearing the cache. If the cache is not fresh, the page shows deleting `var/cache/test` from `tests/bootstrap.php`.

"Customizing Environment Variables": in the test environment Symfony reads `.env`, then `.env.test`, then `.env.test.local`. `.env.local` is not used, "to make each test set-up as consistent as possible."

[Configuration](https://symfony.com/doc/current/configuration.html), "Configuration Environments": a typical application has `dev`, `prod`, and `test`. "`test` for automated tests." "Selecting the Active Environment": `APP_ENV` in `.env` or `.env.local` selects the environment for the web and for console commands. A command can override it, as in `APP_ENV=prod php bin/console command_name`.

The testing page says Flex's default `phpunit.dist.xml` is enough in most cases, and does not paste `APP_ENV`. The Flex recipe that provides that file does. [phpunit/phpunit 11.1 recipe](https://github.com/symfony/recipes/blob/main/phpunit/phpunit/11.1/phpunit.dist.xml) sets `<server name="APP_ENV" value="test" force="true" />`. The recipe's `.env.test` sets `KERNEL_CLASS` and `APP_SECRET`, not `APP_ENV`.

`KernelTestCase::createKernel()` in framework-bundle v8.1.8 resolves the environment as the `environment` option, then `$_ENV['APP_ENV']`, then `$_SERVER['APP_ENV']`, then `'test'`. [KernelTestCase.php](https://github.com/symfony/framework-bundle/blob/v8.1.8/Test/KernelTestCase.php). That default is why a test still lands in `test` when `APP_ENV` is unset. The same file tells you to set `framework.test` to `true` if the `test.service_container` service is missing.

## 1. In-process application test

[Testing](https://symfony.com/doc/current/testing.html), "Application Tests": the workflow is make a request, interact with the page, test the response, and repeat. "Write Your First Application Test": these tests often extend `WebTestCase`, which adds logic on top of `KernelTestCase`. `static::createClient()` calls `bootKernel()` and creates a client "acting as the browser." The sample then calls `$client->request('GET', '/')` and `assertResponseIsSuccessful()`.

"Making Requests": "The test client simulates an HTTP client like a browser and makes requests into your Symfony application." Hardcoding the URL is a best practice. Generating the URL from the router would miss a change to the public path. The documented `request()` signature is method, URI, parameters, files, server, optional raw `content`, and `changeHistory`. The sample POST is form fields: `$client->request('POST', '/form', ['form' => ['_token' => '...']] )`.

The page does not name the client class. `WebTestCase::createClient()` in framework-bundle v8.1.8 returns `KernelBrowser`. [WebTestCase.php](https://github.com/symfony/framework-bundle/blob/v8.1.8/Test/WebTestCase.php). `KernelBrowser` extends `HttpKernelBrowser`. [KernelBrowser.php](https://github.com/symfony/framework-bundle/blob/v8.1.8/KernelBrowser.php). The parent class docblock says it "Simulates a browser and makes requests to an HttpKernel instance." Its `doRequest()` calls `$this->kernel->handle(...)`. [HttpKernelBrowser.php](https://github.com/symfony/http-kernel/blob/v8.1.8/HttpKernelBrowser.php). That is an in-process kernel call. It does not listen on a TCP port and it does not start the PHP built-in server.

[The BrowserKit Component](https://symfony.com/doc/current/components/browser_kit.html), "Creating a Client": the component "simulates the behavior of a web browser." It "does not provide any backend ready to use for the HTTP layer." `HttpBrowser` is the implementation "based on the HTTP layer." The same paragraph points at `HttpClientKernel` for an implementation "based on `HttpKernelInterface`." That class is not the functional-test client. Its docblock says it is "a Symfony HTTP kernel using a real HTTP client," and `handle()` calls `HttpClient`. [HttpClientKernel.php](https://github.com/symfony/http-kernel/blob/v8.1.8/HttpClientKernel.php). `WebTestCase` uses `HttpKernelBrowser`, which calls `kernel->handle()`.

### Packages

"Installation" and "Application Tests": install `symfony/test-pack` with `composer require --dev symfony/test-pack`. The page says that pack installs packages needed for testing, "such as `phpunit/phpunit`." It does not list the rest. The pack's own manifest requires `phpunit/phpunit`, `symfony/browser-kit`, and `symfony/css-selector`, and conflicts with PHPUnit older than 11.1. [symfony/test-pack composer.json](https://github.com/symfony/test-pack/blob/main/composer.json). `symfony/browser-kit` 8.1 requires `symfony/dom-crawler`. [browser-kit composer.json](https://github.com/symfony/browser-kit/blob/8.1/composer.json). `http-client` is only in that component's `require-dev`, so the in-process client does not need it. `framework-bundle` is already installed and contains `WebTestCase` and `KernelBrowser`. This lock does not contain browser-kit, css-selector, or dom-crawler, so `createClient()` cannot run until those are installed.

The testing page also says `request()` returns a `Crawler`, and points at the DOM Crawler. CSS assertions such as `assertSelectorTextContains` are why the pack requires `css-selector`. A status and header check does not need those selectors, but `request()` still returns a crawler, and browser-kit requires dom-crawler.

### JSON body and Content-Type

[The BrowserKit Component](https://symfony.com/doc/current/components/browser_kit.html), "Making Requests": `jsonRequest()` "is a shortcut to convert the request parameters into a JSON string and set the needed HTTP headers." The comment in the sample says it "encodes parameters as JSON and sets the required `CONTENT_TYPE` and `HTTP_ACCEPT` headers." The sample calls it with `GET` and a parameter array. The method argument is the HTTP method, so `POST` is the same call with a different method string.

The testing page says `jsonRequest` "defines the same arguments as the `request()` method." The 8.1 source does not. `jsonRequest(string $method, string $uri, array $parameters = [], array $server = [], bool $changeHistory = true)` JSON-encodes `$parameters` and passes that string as `request()`'s content argument. It sets `CONTENT_TYPE` and `HTTP_ACCEPT` to `application/json`, then clears those server parameters. [AbstractBrowser.php](https://github.com/symfony/browser-kit/blob/8.1/AbstractBrowser.php). A raw JSON document that is not that encoded parameter array uses `request()`'s `$content` argument instead. The testing chapter documents that argument and does not show a `CONTENT_TYPE` example.

"Sending Custom HTTP Headers": headers on `createClient()` or on `request()` follow RFC 3875 section 4.1.18. Replace `-` with `_`, uppercase, and prefix with `HTTP_`. The `jsonRequest` shortcut uses the server name `CONTENT_TYPE`, not `HTTP_CONTENT_TYPE`.

### Status and response Content-Type

"Test Assertions Defined by Symfony": a PHPUnit test may use any PHPUnit assertion. Symfony adds more. The class must extend `WebTestCase` or use `BrowserKitAssertionsTrait` or `WebTestAssertionsTrait`.

"Response Assertions":

- `assertResponseIsSuccessful()` asserts a 2xx status.
- `assertResponseStatusCodeSame(int $expectedCode)` asserts one status code.
- `assertResponseHasHeader('content-type')` asserts the header is present.
- `assertResponseHeaderSame('content-type', 'application/octet-stream')` asserts the header value. The documented sample value is `application/octet-stream`. The page does not show `application/json` as a response Content-Type.

"Request Assertions": `assertRouteSame($expectedRoute, array $parameters = [])` asserts that the request matched that route. That is the documented check that a path was dispatched to a named route, as opposed to only checking the status code.

"Accessing Internal Objects": `$client->getResponse()` is the HttpKernel response. `$client->getRequest()` is the HttpKernel request.

### What this proves, and what it misses

It proves the test process booted the kernel in the test environment, passed a `POST` and a path into `HttpKernel::handle`, and can assert the matched route, the status, and the response Content-Type. It misses a real TCP connection, the PHP built-in server, PHP-FPM, and a reverse proxy. Web-server rewrite rules are outside this path.

## 2. A test that sends a real HTTP request

[Testing](https://symfony.com/doc/current/testing.html), "End to End Tests (E2E)": "If you need to test the application as a whole, including its JavaScript code, you can use a real browser instead of the test client." The tool is Panther. The application-test section above that heading is the simulated client. The testing chapter does not document a PHPUnit test that starts `php -S` itself and calls curl.

[End-to-End Testing](https://symfony.com/doc/current/testing/end_to_end.html), opening: these tests "simulate how real users interact with your application through a browser" and "focus on verifying your user interface." They run in a real browser, headless or not. Install with `composer require --dev symfony/panther`, plus a WebDriver (ChromeDriver or geckodriver).

"Creating a TestCase": `PantherTestCase` "automatically starts your app using the built-in PHP web server." The sample comment says the same. For a Symfony application, `PantherTestCase` extends `WebTestCase`, so the kernel client is still available. The page says you can use Symfony's crawler assertions with Panther. The sample asserts page title and selectors. It does not show `assertRouteSame` on the browser client.

"Changing the Hostname and the Port of the Web Server": the built-in server defaults to `127.0.0.1` and port `9080`. "Using An External Web Server": `external_base_uri` reuses an existing server, and "Panther will not start the built-in PHP web server." `PANTHER_EXTERNAL_BASE_URI` does the same. `PANTHER_APP_ENV` overrides `APP_ENV` for the web server process. `PANTHER_WEB_SERVER_ROUTER` points at a router script. "Assets not Loading when Using the PHP Built-In Server": Panther uses that server; a URI that is not a file under the document root and is not a `.php` file gets a 404 unless a router script forwards it to `index.php`.

"Using Browser-Kit Clients": three clients. `createClient()` "directly manipulates the Symfony kernel" and is the fastest. `createHttpBrowserClient()` uses `HttpBrowser`, which "sends real HTTP requests using the HttpClient component" and does not run JavaScript. `createPantherClient()` is the real browser. The BrowserKit page, "Making External HTTP Requests", shows the same `HttpBrowser` plus `HttpClient::create()` for requests to external sites, after installing the HttpClient component.

Panther 2.4.0 requires `symfony/browser-kit`, `symfony/dom-crawler`, `symfony/http-client`, `symfony/process`, and `php-webdriver/webdriver`. [panther v2.4.0 composer.json](https://github.com/symfony/panther/blob/v2.4.0/composer.json). None of those are direct requirements of this app today. `http-kernel` is already present through framework-bundle.

The pages read here do not show the command `php -S`. They name the PHP built-in web server as the server Panther starts. [Configuring a Web Server](https://symfony.com/doc/current/setup/web_server_configuration.html) says the preferred way to develop is the Symfony local web server, and describes FrankenPHP, Caddy, Nginx, and Apache for production. It does not document `php -S`.

What a Panther browser test proves: a real browser sent HTTP to a running server (the built-in server, or whatever `external_base_uri` names) and the response rendered. What it misses: it is documented for the user interface and JavaScript, not as the way to prove one JSON route. The built-in server is not PHP-FPM behind a reverse proxy. `HttpBrowser` proves a real HTTP request without a browser, and misses JavaScript. Pointing `external_base_uri` at a later PHP-FPM proxy would exercise that proxy; the docs present that as reusing an existing server, not as the default.

## 3. A manual check during development

[Installing & Setting up the Symfony Framework](https://symfony.com/doc/current/setup.html), "Running Symfony Applications": in production, install Nginx or Apache. For local development, "the most convenient way" is the Symfony CLI local web server. The commands are `symfony server:start` (foreground) and `symfony serve -d` (background). Then browse the URL the server prints, for example `http://localhost:8000/`. `symfony open:local` opens that URL. `symfony server:start --open` starts and opens it.

[Symfony CLI](https://symfony.com/doc/current/setup/symfony_cli.html), "Serving Your Application": `symfony server:start` listens on `http://127.0.0.1:8000` in the sample, and `symfony open:local` opens it. `server:start -d`, `server:log`, and `server:stop` run it in the background. "Enabling PHP-FPM": if a front controller such as `public/index.php` is found, the Symfony server starts with PHP-FPM. PHP-FPM must be installed locally. The same page's only curl example is under "Defining the Local Domain", for the local proxy: `https_proxy=$(symfony proxy:url) curl https://my-domain.wip`. That is not a documented `POST` of a JSON body to an API route.

[Configuring a Web Server](https://symfony.com/doc/current/setup/web_server_configuration.html): the preferred development server is the Symfony local web server. The rest of that page is FrankenPHP, Caddy, Nginx, and Apache with PHP-FPM, for when the application runs in production. The public directory is the document root, and `index.php` is the front controller.

[Routing](https://symfony.com/doc/current/routing.html), "Debugging Routes": `php bin/console debug:router` lists routes, methods, and paths. `--method=POST` limits the list to that verb. Passing a route name prints that route. `router:match` shows which route matches a URL. The sample is `php bin/console router:match /lucky/number/8`. These commands inspect the router. They do not send an HTTP request and they do not call the controller.

`symfony/console` is already a direct dependency, so `debug:router` does not need a new package. The documented local server does need the Symfony CLI binary. This image's Dockerfile does not install it, and `bin/docker-run` does not publish a port.

## 4. An agent-driven browser check

The testing chapter and the end-to-end chapter do not describe an agent driving a browser. They do not mention Cursor, an MCP browser, or an agent clicking through a page. Panther is a PHPUnit client on top of WebDriver. That is option 2, not an agent. There is no Symfony feature to invent here.

## Comparison

| Option | What it proves | What it misses | Extra packages |
| --- | --- | --- | --- |
| In-process `WebTestCase` / `KernelBrowser` | Kernel handled `POST` and the path. `assertRouteSame` checks the matched route. Status and response Content-Type are assertable. `jsonRequest` sends a JSON parameter array with `Content-Type: application/json`. | No TCP listener, no PHP built-in server, no PHP-FPM, no reverse proxy. | `symfony/browser-kit` and `symfony/css-selector` from `symfony/test-pack` (`phpunit` is already required). browser-kit brings `symfony/dom-crawler`. Not `http-client`. Not Panther. |
| Panther, or Panther's `HttpBrowser` | A real HTTP client hit a running server: the PHP built-in server Panther starts, or an external base URI. The browser client also runs JavaScript. | Documented for the UI and JavaScript. The built-in server is not the later PHP-FPM proxy. `HttpBrowser` misses JavaScript. Needs a browser driver for the real browser. | `symfony/panther`, which requires browser-kit, dom-crawler, http-client, process, and `php-webdriver/webdriver`, plus ChromeDriver or geckodriver. |
| Manual server, browser, curl, `debug:router` | A person can open the URL the Symfony server prints. `debug:router` and `router:match` show that the route exists and that a path matches. | Not a regression test. Router commands do not invoke the action. The documented server is `symfony server:start` / `symfony serve`. This image has no Symfony CLI, and `docker-run` publishes no port. Docs read here do not show `php -S` or a JSON `POST` via curl. | No Composer package for `debug:router`. The local server needs the Symfony CLI, which is not in the image. |
| Agent-driven browser | Nothing in the testing or end-to-end docs. | No official setup, assertion, or claim about what an agent browser would prove. | None documented. |
