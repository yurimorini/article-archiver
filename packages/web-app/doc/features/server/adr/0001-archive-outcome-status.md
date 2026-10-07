# Archive outcomes use four HTTP status classes

Status: proposed. This is a research direction, not a final decision. A later session can replace it. A spec adopts it when implementation starts.

`POST /api/archives` either stores an archive or fails with a reason. Clients need to tell a page that is not an article from a URL Eleanor rejects and from a technical fault, without reading the body. Validation is 400, including an invalid JSON body. A soft failure is 422: no article, a redirect Eleanor does not follow, a non-HTML content type, or a body over the cap. An upstream transport or HTTP status failure is 502. Anything else — a stage abort, an unexpected failure, a write that did not store the archive, or an unclassified throwable — is 500. Success, the archive on disk, is 201.

Symfony's default 422 for a failed payload validation would make "this page is not an article" look like "the JSON was invalid". One status for every client failure would force the client to parse the body before it could say which. The operator cannot know in advance whether a URL is an article, so the soft class has a status of its own.
