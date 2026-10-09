# Archive route

`POST /api/archives`

One call names one page URL. The first stored archive for that URL is a create. A later POST of the same URL is recorded in [Types.md](Types.md). The URL travels in the JSON body, not in the path, in the property `url`. The path has no trailing slash.

`/api` is the JSON prefix. A route under `/api` returns JSON. HTML pages, when they exist, live outside that prefix.

The path has no version segment. Evolution is the preference until an incompatible change is needed.

The request and the success response use `application/json`. The route does not negotiate a media type.

The error document is recorded in [Types.md](Types.md). CORS, HTTP caching, and CSRF stay open. The status of each outcome is recorded in [Flow.md](Flow.md).

Archive, article, and URL pointer are defined in the package [GLOSSARY.md](../../../GLOSSARY.md).
