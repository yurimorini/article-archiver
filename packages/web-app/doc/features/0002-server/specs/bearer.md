**Status:** ready-for-agent

## Problem Statement

`POST /api/archives` archives a URL pointer for the Owner named by the configured email, and it answers anyone who can reach the server. A script, a browser, or a phone on the same machine can store an archive without proving it may act as that Owner. The server is meant to stay on a private network, and that is not a check. The Owner has no reminder, in the place they already read, of where the secret lives or of what a refused call looks like.

## Solution

Every call under `/api` requires the configured bearer secret. The client sends it as `Authorization: Bearer <secret>`. A call without a valid secret is refused before the body is read, with the same JSON error shape the client already parses. The secret that matches authenticates the Owner identified by the configured email, and the archive is still that Owner's. If the secret was never configured, the server stays up and the call is a server failure, not a refusal of the client. The README tells the Owner where to put the secret and what those two answers look like.

## User Stories

### A call that proves the Owner

1. As a client of Eleanor, I want `POST /api/archives` with the configured secret to answer 201 with `url` and `title` as `application/json`, so that a permitted call still archives a URL pointer.
2. As a client of Eleanor, I want a second POST of the same URL pointer, with the same secret, to answer 200 with the same JSON and not fetch again, so that the archive command is unchanged.
3. As a client of Eleanor, I want the success JSON to stay `url` and `title`, so that the secret and the Owner email never appear in a success.
4. As a client of Eleanor, I want to send the secret in `Authorization` as `Bearer <secret>`, so that one header is the whole credential.
5. As a client of Eleanor, I want `bearer` and `BEARER` to be the same scheme, so that the capitalisation of the scheme does not refuse a valid secret.
6. As a client of Eleanor, I want the secret compared exactly, with no trimming, so that a space I did not configure is not part of the secret.
7. As the Owner, I want a matching secret to act as the Owner named by the configured email, so that the archive is stored for that email.
8. As the Owner, I want the secret to carry no email, so that changing the configured email points the same secret at another Owner.
9. As a client of Eleanor, I want a permitted call to create no session cookie, so that each call stands on the secret alone.

### A call that does not prove the Owner

10. As a client of Eleanor, I want a missing `Authorization` header on a matched `/api` route to answer 401 `unauthenticated`, so that I know the call is not authenticated.
11. As a client of Eleanor, I want a scheme other than Bearer, an empty value after Bearer, and a secret that does not match to answer that same 401, that same `code`, and that same `message`, so that the body does not tell me which check failed.
12. As a client of Eleanor, I want `message` to be `The request is not authenticated.`, so that the sentence is fixed and safe to show.
13. As a client of Eleanor, I want that body to be `application/json` with exactly `code` and `message`, so that a refusal parses like every other `/api` failure.
14. As a client of Eleanor, I want the body not to contain the secret, the header I sent, or the Owner email, so that a refusal does not leak them.
15. As a client of Eleanor, I want the 401 to carry no `WWW-Authenticate` header, so that the contract stays on `code`.
16. As a client of Eleanor, I want the 401 to carry no `X-Request-Id`, so that a call that never reached the action stays as the logging contract left it.
17. As a client of Eleanor, I want a broken JSON body, or a body that is not JSON, sent without a valid secret, to answer 401 and not 400 or 415, so that the body is not judged before the secret.
18. As a client of Eleanor, I want a valid secret and a broken JSON body to answer 400 `invalid_json`, and a valid secret with a wrong body to answer 400 `invalid_body`, so that those codes stay once I am authenticated.
19. As a client of Eleanor, I want a valid secret and a wrong media type to answer 415 `unsupported_media_type`, so that 415 stays once I am authenticated.
20. As a client of Eleanor, I want `GET` and `HEAD` on the archive route to answer 405 `method_not_allowed` with `Allow` and no `X-Request-Id`, whether or not I sent a secret, so that a wrong method is still decided by the route.
21. As a client of Eleanor, I want a `HEAD` answer to carry the status and headers and no body, so that the response follows HTTP.
22. As a client of Eleanor, I want an unknown path under `/api` to answer 404 `not_found` with no `X-Request-Id`, whether or not I sent a secret, so that an unknown path is still decided by the route.
23. As a client of Eleanor, I want every matched route under `/api`, not only `POST /api/archives`, to require the secret, so that a later route does not appear open.
24. As a client of Eleanor, I want no 403 from this slice, so that a call is either the Owner or not authenticated.

### A secret that was not configured

25. As a client of Eleanor, I want a missing `ELEANOR_BEARER_TOKEN` to leave the server accepting connections, so that the process does not disappear.
26. As a client of Eleanor, I want a missing or empty `ELEANOR_BEARER_TOKEN` on a call under `/api` to answer 500 `unexpected` with `Something went wrong on the server.`, so that a server fault is not told as a bad secret.
27. As a client of Eleanor, I want that 500 body to stay `code` and `message` and not to name the variable, so that the sentence stays the one already fixed for `unexpected`.
28. As the Owner, I want the log to name `ELEANOR_BEARER_TOKEN` when it is missing or empty, so that I can see why the server failed.

### What the Owner configures

29. As the Owner, I want the secret in the environment variable `ELEANOR_BEARER_TOKEN`, so that it is not committed next to the email.
30. As the Owner, I want the real secret in the uncommitted local environment file, so that the committed files keep no production secret.
31. As the Owner, I want the email to stay the configured email parameter, so that the Owner and the secret remain two settings.
32. As the Owner, I want the README to say where the secret goes, which header to send, what a 401 looks like, and what a 500 looks like when the variable is missing or empty, so that I do not have to reconstruct it from the code.

### What a developer keeps stable

33. As a developer of Eleanor, I want the archive route to keep receiving the Owner's archive service as an action argument, so that the controller does not grow a credential check.
34. As a developer of Eleanor, I want the Owner of the call to be the authenticated Owner, so that a later login can supply a different Owner through the same port.
35. As a developer of Eleanor, I want the firewall not to store the caller in a session, so that the stateless `/api` import and the firewall agree.
36. As a developer of Eleanor, I want a missing header and a wrong secret to write two different lines on the `application` channel, so that I can tell them apart in the log.
37. As a developer of Eleanor, I want those lines not to contain the secret and not to carry a request id, so that the log does not keep the credential and does not invent an id the response lacks.
38. As a developer of Eleanor, I want the translator that already answers `/api` failures to stay silent on a 401, so that a client refusal still writes no error line.
39. As a developer of Eleanor, I want a 401 to write no `http.request.start` and no `http.request.finish`, so that the request lines stay tied to a call that reached the action.
40. As a developer of Eleanor, I want the quality checks to pass for `web-app`, so that the slice meets the same bar as the earlier ones.

## Implementation Decisions

- Slices 8 and 9 of [feature.md](../feature.md) are one slice. The contract is this file. No ADR is added. The glossary gains no term. The authenticated caller is the Owner. "User" stays an avoided word for that person.
- This contract adopts the configured bearer from [research/Login.md](../research/Login.md) and does not adopt human login, the passkey, or the session. It adopts the 401 that [error-response.md](error-response.md) left to the firewall and token slices, and it does not adopt the 403. A 403 waits until a call can be authenticated and still lack a permission.
- The route import stays the stateless `/api` import of [adr/0005](../../../adr/0005-api-routes-are-a-stateless-import.md). One firewall covers `/api`, is stateless, and requires an authenticated caller. A path outside `/api` is not this firewall's. The router still runs first. An unknown path stays 404 and a wrong method stays 405, with `Allow` on the 405, without the secret being judged. A matched route is judged before the body mapper runs.
- The credential is the `Authorization` header. The scheme is Bearer, compared without regard to case. The value after the scheme is compared to `ELEANOR_BEARER_TOKEN` exactly. A missing header, another scheme, an empty value, and a value that does not match are one failure.
- That failure answers 401 with `code` `unauthenticated` and `message` `The request is not authenticated.` The content type is `application/json`. There is no `WWW-Authenticate` header. There is no `X-Request-Id`. Symfony's own authentication page does not replace this JSON.
- `ELEANOR_BEARER_TOKEN` is an environment variable. A committed file holds no production value. The local uncommitted environment file holds the Owner's value. The test environment holds a test value so the kernel boots. The email parameter is unchanged and is not read from the secret.
- When the secret matches, the authenticated Owner's email is that parameter. The port that supplies the Owner of a call reads that authenticated Owner. The controller, the archive service, and the factory keep their present jobs. The archive is still that Owner's.
- A missing or empty `ELEANOR_BEARER_TOKEN` is not an authentication failure. The process keeps accepting connections. A call under `/api` is the existing 500 `unexpected` with `Something went wrong on the server.` The log names the variable. The client sentence does not.
- A missing header and a wrong secret each write one line on the `application` channel. The lines differ. Neither contains the secret or a request id. The exception translator writes nothing for the 401. The request start and finish lines are not written, because the action did not run.
- The security component of Symfony is added. Eleanor does not grow a second credential check in the controller.
- The execution plan adds the README section. The section states the variable name, the local uncommitted file, the `Authorization: Bearer` header, the 401 `unauthenticated` body, and the 500 `unexpected` body when the variable is missing or empty. It states that the email parameter is still the Owner.

## Testing Decisions

- A good test observes status, content type, the JSON `code` and `message`, and the headers the contract names. It does not assert a Symfony class name, a private call order, a log file, or the wording of a framework exception.
- One seam: the existing kernel test of `/api`. It drives the call through the kernel. With the test secret, a new archive stays 201 and a repeat stays 200, with `url` and `title`. Without a valid secret, a matched route answers 401 `unauthenticated` and `The request is not authenticated.`, with no `WWW-Authenticate` and no `X-Request-Id`, and the body is not the reason for a 400 or a 415. With the secret, a broken body stays 400 and a wrong media type stays 415. A wrong method stays 405 with `Allow` and no `X-Request-Id`. An unknown path stays 404 with no `X-Request-Id`. A boot with the variable missing or empty answers 500 `unexpected` and `Something went wrong on the server.`
- The two 401 causes are proved identical at that seam. The lines that distinguish them are not read back from a log file. No second seam is added for them.
- Existing kernel calls that expect a success or a body failure send the test secret. Calls that expect 404 or 405 need not send it.
- Prior art is the kernel test of the archive route and the kernel test of the error response. A unit test that does not boot the kernel is not this seam.
- A real network fetch, a browser, and a log-file assertion are not seams.

## Out of Scope

- A 403, a role, and any authenticated caller who is not allowed to archive.
- Human login, a passkey, a session cookie, and a second way to authenticate.
- A second Owner, and a secret that selects an email.
- `WWW-Authenticate` and any challenge header.
- An ADR, and a new glossary term.
- HTML routes, and a path outside `/api`.
- Moving the request id onto a call that does not reach the action.
- Opening a log file from a test.
- The runtime slice that serves PHP-FPM behind the reverse proxy.
- A committed production secret.

## Further Notes

- [feature.md](../feature.md) records the merge of the firewall row and the token row into this slice. The module spec and the execution plan come next. The plan is where the README section is written.
- [error-response.md](error-response.md) left 401 and 403 to the firewall and token slices. This contract takes the 401. The 403 stays out of scope there as well, until some later slice has a permission to refuse.
- [research/Login.md](../research/Login.md) remains the record of human login. This contract does not promote that record into a decision.
