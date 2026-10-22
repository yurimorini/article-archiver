# The archive body is mapped by MapRequestPayload

Status: accepted. The [payload-and-mapper spec](../features/0002-server/design/PayloadAndMapper.md) adopts this.

`POST /api/archives` maps the JSON body with `#[MapRequestPayload]` before the action runs. The action method stays `create`. Eleanor adds `symfony/serializer`, `symfony/validator`, and `symfony/property-access` at `8.1.*`. Property access stays, because FrameworkBundle removes the object normalizer without it. The body DTO is `Yumo\Eleanor\Api\Dto\CreateArchiveBody` in `src/Api/Dto/`. It is not a controller. The route import loads `src/Api/Controller/` and does not load this directory. `CreateArchiveBody` has one property, `?string $url = null`, with `NotNull` and without `NotBlank`. The attribute sets `acceptFormat: 'json'` and `validationFailedStatusCode: 400`. Unknown properties are ignored. Any string, including an empty one, reaches the action. `UrlGuard` is the later judge of that string.

A required `string $url` misses the resolver's catch list: `MissingConstructorArgumentsException` becomes a 500. The nullable default plus `NotNull` is a validation failure, so the status is 400. `NotBlank` would reject `""`, and this command gives every string to the service.

Malformed JSON, an empty body sent as `application/json`, a missing `url`, a non-string `url`, and a JSON value that is not that object are 400. A missing `Content-Type`, or a media type other than JSON, is 415. [0001](0001-archive-outcome-status.md) still owns the archive outcomes. 415 is the media type, before that command. The refusal body is Symfony's JSON error. Eleanor's problem document, with `cause`, waits for the response slice. Until that slice, an accepted body still returns the empty-route `200` `{"ok":true}`. The action does not call the archive service.

Reading the body in the action was the alternative. The resolver already stops before `create`, ignores unknown fields, and turns type failures into the validation status.
