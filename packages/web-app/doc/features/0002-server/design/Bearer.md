# Bearer

Module design for [bearer.md](../specs/bearer.md). The decisions in that contract are closed. This slice adds no glossary term and no ADR. It adopts the stateless `/api` import in [adr/0005](../../../adr/0005-api-routes-are-a-stateless-import.md).

A later login adds a second authenticator on the same firewall. `BearerAuthenticator` stays the check of `ELEANOR_BEARER_TOKEN`. The second authenticator validates its own credential and puts an email on the identity. `AuthenticatedOwnerProvider` reads that email and does not learn which authenticator wrote it. This slice does not add the second authenticator, a password check, a JWT, or a cookie.

## Revises

[ArchiveCommand.md](ArchiveCommand.md) and [OwnerScopedService.md](OwnerScopedService.md) leave `OwnerProvider` as the source of the `Owner`. The HTTP alias becomes `AuthenticatedOwnerProvider`. `ConfiguredOwnerProvider` stays a class. It is no longer that alias. `ArchiveServiceResolver` and `CreateArchiveController` stay as they are.

[error-response.md](../specs/error-response.md) left 401 to this slice and maps every unknown throwable to 500 `unexpected`. This slice adds `unauthenticated`. A missing secret stays on that 500 path and writes its own log line first.

## Modules

`Yumo\Eleanor\Api\Security\EmailIdentity` is a final class. It implements `UserInterface`. The constructor takes the email string. `getUserIdentifier()` returns that string. `getRoles()` returns an empty list. `eraseCredentials()` does nothing. It is not an `Owner`. `Owner` still lowercases the email.

`Yumo\Eleanor\Api\Security\BearerNotConfigured` is a final class extending `\RuntimeException`. It is not an authentication exception. The message is fixed: `ELEANOR_BEARER_TOKEN is empty.`

`Yumo\Eleanor\Api\Security\BearerAuthenticator` is a final class extending `AbstractAuthenticator`. The constructor takes the configured secret, the configured owner email, and the `application` logger. `supports()` returns true for every request the firewall passes in. `onAuthenticationSuccess()` returns null. `onAuthenticationFailure()` returns null. The passport is a `SelfValidatingPassport`. Its `UserBadge` loader returns `new EmailIdentity($email)` and does not consult a user provider. The comparison of the secret uses `hash_equals`.

`Yumo\Eleanor\Api\Owner\AuthenticatedOwnerProvider` is a final class implementing `OwnerProvider`. It reads the security token. `owner()` returns `new Owner($user->getUserIdentifier())` when the token's user implements `UserInterface`. Otherwise it throws `\LogicException`. The resolver only runs after a successful authentication, so that throw is a programming fault. A later authenticator puts its own email on the token. This provider does not name `EmailIdentity`.

`RequestFailure` gains `Unauthenticated = 'unauthenticated'`. Its status is 401. Its message is `The request is not authenticated.`

`ErrorTranslator` maps an `AuthenticationException`, and an `AccessDeniedException` whose token is not a full authentication, to that failure. Any other throwable, including `BearerNotConfigured`, stays 500 `unexpected`.

`TranslateApiException` still writes the JSON for every `/api` failure. After it sets the response for `unauthenticated`, it stops propagation. The security exception listener does not replace that body. Other failures do not stop propagation.

## Configuration

`symfony/security-bundle` is added. One firewall is named `api`. Its pattern is `^/api`, it is stateless, and its only custom authenticator is `BearerAuthenticator`. It has no entry point and no password hasher. It has no user provider: the passport loader builds the identity. `access_control` requires `IS_AUTHENTICATED` on `^/api`. A request that matches no firewall is unchanged.

`ELEANOR_BEARER_TOKEN` is read with an empty default, so a missing variable and an empty variable are both the empty string. The committed environment file does not hold a production secret. `.env.test` holds the test secret. The Owner's secret belongs in the uncommitted local environment file.

`eleanor.owner_email` stays the configured email. The `OwnerProvider` alias is `AuthenticatedOwnerProvider`.

The execution plan adds the README section. It names the variable, the local uncommitted file, the header `Authorization: Bearer`, the 401 body, and the 500 body when the variable is missing or empty. It says the email parameter is still the Owner.

## Data flow

The router runs first. An unknown path is 404 `not_found`. A wrong method on the archive route is 405 `method_not_allowed` with `Allow`. The firewall does not see them. They carry no `X-Request-Id` and no `http.request` line.

On a matched route under `/api` the firewall calls `BearerAuthenticator`.

When the secret is empty, the authenticator does not read `Authorization`. It writes `bearer.not_configured` and throws `BearerNotConfigured`. `ErrorTranslator` answers 500 `unexpected` with `Something went wrong on the server.` The client sentence does not name the variable. `TranslateApiException` also writes `http.request.failed`, as it does for every 500. The line that identifies this fault is `bearer.not_configured`.

When the secret is present, the authenticator reads `Authorization`. The scheme `Bearer` is compared without regard to case. The value after the scheme is compared as it is. An extra space does not match.

A missing header writes `bearer.absent` and throws an authentication exception. A header that is present, with another scheme, an empty value, or a value that is not the secret, writes `bearer.rejected` and throws an authentication exception. Neither line contains the secret or a request id. `onAuthenticationFailure()` returns null. `access_control` then denies the unauthenticated caller. The translator answers 401 `unauthenticated` with `The request is not authenticated.`, no `WWW-Authenticate`, and no `X-Request-Id`. It writes no error line. Propagation stops. The body is not mapped, so a broken body or a wrong media type on this path stays 401.

When the secret matches, the passport carries `EmailIdentity` for the configured email. `access_control` allows the request. The body is mapped. `ArchiveServiceResolver` calls `AuthenticatedOwnerProvider`, which builds the `Owner` from the identity. The action runs. `LogApiRequest` writes `http.request.start` and `http.request.finish`, and the response carries `X-Request-Id`. The firewall writes no session.

With a valid secret, a broken body stays 400 and a wrong media type stays 415.

## Lines

All three lines are channel `application`.

| Message | Level | When |
| --- | --- | --- |
| `bearer.absent` | info | The secret is configured and `Authorization` is absent. |
| `bearer.rejected` | info | The secret is configured and the header is not that secret. |
| `bearer.not_configured` | error | `ELEANOR_BEARER_TOKEN` is missing or empty. |

`bearer.not_configured` context is `variable` with the value `ELEANOR_BEARER_TOKEN`. The other two lines have no context. None of the three include the header value, the secret, or a request id.

## Tests

One seam: the kernel test of `/api`, the same seam as the archive route and the error response. It observes status, content type, `code`, `message`, and the headers this spec names. It does not open a log file, name a Symfony class, or assert call order. The three log lines are specified above and are not read back.

`.env.test` holds `ELEANOR_BEARER_TOKEN`. A test that expects 201, 200, 400, or 415 sends `Authorization: Bearer` with the value from that environment. A test that expects 404 or 405 may omit it.

Without a valid secret, a matched route answers 401 `unauthenticated` and `The request is not authenticated.`, with no `WWW-Authenticate` and no `X-Request-Id`. A missing header, another scheme, an empty value, and a wrong secret are the same response. A broken body on that path stays 401.

With the test secret, a new archive stays 201, a repeat stays 200, a broken body stays 400, and a wrong media type stays 415. A wrong method stays 405 with `Allow` and no `X-Request-Id`. An unknown path stays 404 with no `X-Request-Id`.

A kernel booted with the variable missing or empty, before any request, answers 500 `unexpected` and `Something went wrong on the server.`

`ConfiguredOwnerProvider` keeps its unit test. No second seam is added. The kernel cannot tell the email parameter from the identity, because this slice puts the same email on both.

## Out of scope

A second authenticator, a JWT, a cookie, a password, a 403, a role, `WWW-Authenticate`, a session, and a test that reads a log file.
