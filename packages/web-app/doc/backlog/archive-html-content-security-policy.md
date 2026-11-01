# Content-Security-Policy on the archive HTML

`GET /api/archives/{id}.html` answers `text/html; charset=UTF-8` with the stored fragment and no template. That is the archive-detail contract. The response sends no `Content-Security-Policy`.

A browser that renders that response can run script that purification left in the fragment. The metadata JSON and `includeContent=true` do not have this exposure: they are `application/json`.

This is not a change to the stored HTML and not a page template. The fragment stays the body. The open question is a header, or another control, that stops a browser from executing that body.
