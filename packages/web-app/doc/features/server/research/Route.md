# Archive route

`POST /api/archives`

One call adds one archive. The URL travels in the JSON body, not in the path. The path has no trailing slash. The name of the URL field is still open.

`/api` is the JSON prefix. A route under `/api` returns JSON. HTML pages, when they exist, live outside that prefix.

The path has no version segment. Evolution is the preference until an incompatible change is needed.

The request and the success response use `application/json`. The route does not negotiate a media type.

The error document, CORS, HTTP caching, and CSRF stay open. The status of each outcome is recorded in [Flow.md](Flow.md).

Archive, article, and URL pointer are defined in the repository `GLOSSARY.md`.
