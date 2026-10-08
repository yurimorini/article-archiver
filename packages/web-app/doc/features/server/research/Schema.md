# Schema checks

This file records a research session. It is not a final decision. A later session can replace it. A spec adopts a tool when implementation starts. The payloads are the ones named in [Types.md](Types.md).

The pages below were read on 9 October 2026. Symfony pages were read at `/doc/7.4/`, `/doc/8.0/`, and `/doc/current/`. The current controller page includes `#[Serialize]` and says that attribute was introduced in Symfony 8.1.

Three checks stay separate in this note.

- Symfony Validator constraints on a PHP DTO. `#[MapRequestPayload]` runs them before the action.
- A JSON Schema or OpenAPI schema checked against a JSON document.
- A document generated from a schema, or a schema generated from PHP attributes.

## What HTTP and OpenAPI actually require

[RFC 9110, Content](https://www.rfc-editor.org/rfc/rfc9110.html#section-6.4) defines message content as the octets after the header section, once framing is removed. [Content Semantics](https://www.rfc-editor.org/rfc/rfc9110.html#section-6.4.1) says the purpose of request content comes from the method. For POST, [POST](https://www.rfc-editor.org/rfc/rfc9110.html#section-9.3.3) says the target resource processes the enclosed representation according to its own semantics. [Content-Type](https://www.rfc-editor.org/rfc/rfc9110.html#section-8.3) says the media type names the data format and how a recipient is intended to process that data, after any content coding is decoded.

[400 Bad Request](https://www.rfc-editor.org/rfc/rfc9110.html#section-15.5.1) is for a perceived client error, with malformed request syntax given as an example. [415 Unsupported Media Type](https://www.rfc-editor.org/rfc/rfc9110.html#section-15.5.16) is for content in a format the method does not support on that resource. [422 Unprocessable Content](https://www.rfc-editor.org/rfc/rfc9110.html#section-15.5.21) is for content whose type the server understands and whose syntax is correct, when the server cannot process the contained instructions. The rendered RFC 9110 text does not contain the word "schema". HTTP requires the recipient to interpret content under the method, the status, and the media type. A comparison of that content to a JSON Schema or an OpenAPI schema is outside this RFC.

[OpenAPI Specification 3.1.2](https://spec.openapis.org/oas/v3.1.2.html) (19 September 2025), Introduction, says an OpenAPI Description lets humans and computers discover the service, and that documentation tools, code generators, and testing tools can use it. [Request Body Object](https://spec.openapis.org/oas/v3.1.2.html#request-body-object) carries a required `content` map from a media type to a Media Type Object. [Media Type Object](https://spec.openapis.org/oas/v3.1.2.html#media-type-object) has a `schema` field: "The schema defining the content of the request, response, parameter, or header." [Response Object](https://spec.openapis.org/oas/v3.1.2.html#response-object) has a `content` map described the same way, for potential response payloads. [Schema Object](https://spec.openapis.org/oas/v3.1.2.html#schema-object) "allows the definition of input and output data types" and is a superset of JSON Schema draft 2020-12. [Specifying Schema Dialects](https://spec.openapis.org/oas/v3.1.2.html#specifying-schema-dialects) says that when `jsonSchemaDialect` is absent, the OAS dialect schema id applies. That dialect includes the draft 2020-12 vocabularies. A `$schema` on a schema resource root overrides the default, so a Schema Object may use another draft.

The 3.1 text describes what a tool may do with those schemas. Tooling may validate that an example matches its schema ([Working with Examples](https://spec.openapis.org/oas/v3.1.2.html#working-with-examples): "Tooling implementations MAY choose to validate compatibility automatically"). OAS implementations may use annotations as the basis for further validation ([Extended Validation with Annotations](https://spec.openapis.org/oas/v3.1.2.html#extended-validation-with-annotations)). Where JSON Schema leaves behavior to the application, OAS leaves it to the application consuming the document. The specification does not say that a server must reject a request or a response that fails a Schema Object.

OpenAPI 3.0 differs in the dialect. [OpenAPI 3.0.4, Data Types](https://spec.openapis.org/oas/v3.0.4.html#data-types) bases types on JSON Schema Validation Draft Wright-00, excluding `null`, and points to `nullable` for null. Its [Schema Object](https://spec.openapis.org/oas/v3.0.4.html#schema-object) is an extended subset of that draft. Draft 2020-12, which 3.1 uses, is the dialect that defines `unevaluatedProperties`.

[JSON Schema's specification page](https://json-schema.org/specification) says the current version is 2020-12. The documents read here are the Internet-Drafts published 16 June 2022: [JSON Schema Core](https://json-schema.org/draft/2020-12/json-schema-core.html) (`draft-bhutton-json-schema-01`) and [JSON Schema Validation](https://json-schema.org/draft/2020-12/json-schema-validation.html) (`draft-bhutton-json-schema-validation-01`). Core, [Instance](https://json-schema.org/draft/2020-12/json-schema-core.html#section-4.2), says a JSON document to which a schema is applied is an instance. Validation, [Overview](https://json-schema.org/draft/2020-12/json-schema-validation.html#section-3), says validation asserts constraints on instance data. When every location in the instance satisfies every asserted constraint, the instance is valid against the schema. Core, [Assertions](https://json-schema.org/draft/2020-12/json-schema-core.html#section-7.6), says an instance can only fail an assertion that is present.

`additionalProperties` is the keyword for object properties whose names are outside `properties` and `patternProperties`. Core, [additionalProperties](https://json-schema.org/draft/2020-12/json-schema-core.html#section-10.3.2.3), says validation of those properties succeeds when each value validates against the `additionalProperties` subschema. Omitting the keyword has the same assertion behavior as an empty schema. An empty schema has no assertions, so those properties validate. The boolean schema `true`, and the empty schema `{}`, are the same acceptance written out. The boolean schema `false` rejects them. `unevaluatedProperties` is the broader keyword. Core, [unevaluatedProperties](https://json-schema.org/draft/2020-12/json-schema-core.html#section-11.3), applies a subschema to properties that were not successfully evaluated by adjacent `properties`, `patternProperties`, `additionalProperties`, or in-place applicators such as `allOf`. Omitting it also has the empty-schema behavior. Setting it to `false` rejects the unevaluated names. JSON Schema records whether those properties are valid. It does not define a keyword that removes them from the document.

[RFC 9457](https://www.rfc-editor.org/rfc/rfc9457.html) defines `application/problem+json` for a JSON object that carries problem details. [Members of a Problem Details Object](https://www.rfc-editor.org/rfc/rfc9457.html#section-3.1) defines `type`, `status` (a JSON number), `title`, `detail`, and `instance`. [Extension Members](https://www.rfc-editor.org/rfc/rfc9457.html#section-3.2) says a problem type may add members, and that clients must ignore extension members they do not recognize. [Appendix A](https://www.rfc-editor.org/rfc/rfc9457.html#appendix-A) gives a non-normative JSON Schema, draft 2020-12, for those five members. The appendix says the specification text prevails if the schema disagrees. The schema does not list `required`, and it does not set `additionalProperties`. Eleanor's `cause` is an extension member under section 3.2.

## Symfony: object validation versus a document schema

[Validation](https://symfony.com/doc/7.4/validation.html), "The Basics of Validation", says the Validator tells you whether the data of an object is valid. Constraints are the rules, usually PHP attributes such as `#[Assert\NotBlank]`, and also YAML or XML. The same opening is on [8.0](https://symfony.com/doc/8.0/validation.html) and [current](https://symfony.com/doc/current/validation.html). All three also say Symfony provides a JSON Schema for validation mapping files so an IDE can complete and check those YAML files. That schema describes the mapping file. It is not a schema of an HTTP body.

[Controller, Mapping Request Payload](https://symfony.com/doc/7.4/controller.html#mapping-request-payload) says `#[MapRequestPayload]` maps a POST or PUT payload onto a typed argument. The same section on [8.0](https://symfony.com/doc/8.0/controller.html#mapping-request-payload) and [current](https://symfony.com/doc/current/controller.html#mapping-request-payload) says the default status when validation fails is 422, and that `validationFailedStatusCode` changes it. The query-string section on the 7.4 page shows `#[Assert\NotBlank]` on the DTO and calls those "optional validation constraints." The payload section uses `validationGroups`. This is the Validator running on the mapped object, before the action. [Types.md](Types.md) already sets `validationFailedStatusCode` to 400 so that failure stays apart from the 422 causes.

[Serializer, Deserializing an Object](https://symfony.com/doc/7.4/serializer.html#deserializing-an-object) says `deserialize()` decodes data into a class. The same section on [8.0](https://symfony.com/doc/8.0/serializer.html) and [current](https://symfony.com/doc/current/serializer.html) says that, by default, additional attributes that are not mapped to the object are ignored. Setting `allow_extra_attributes` to `false` is how the docs show a rejection of those attributes. [Serializing an Object](https://symfony.com/doc/7.4/serializer.html#serializing-an-object) and [The Serialization Process](https://symfony.com/doc/7.4/serializer.html#the-serialization-process-normalizers-and-encoders) describe normalizers turning objects into arrays and encoders turning arrays into JSON. The 7.4, 8.0, and current serializer pages describe a JSON Schema for serializer mapping files, for IDE completion. They do not describe comparing the encoded JSON to a JSON Schema.

[Returning JSON Response](https://symfony.com/doc/7.4/controller.html) on 7.4, 8.0, and current says `json()` uses the serializer when it is enabled, and `json_encode` otherwise. [Serializing Controller Return Values Automatically](https://symfony.com/doc/current/controller.html#serializing-controller-return-values-automatically) is on the current page. `#[Serialize]` serializes the returned object from the request format, default JSON, and sets `Content-Type`. The section does not describe a schema check of that JSON. 7.4 and 8.0 document `JsonResponse` and do not document `#[Serialize]`.

The [Symfony packages index](https://symfony.com/doc/current/components.html) lists Serializer and Validator. It lists no OpenAPI package. The serializer pages point at the API Platform project for OpenAPI encoders. [Symfony AI, Agent](https://symfony.com/doc/current/ai/components/agent.html), "Parameter Validation with `#[Schema]`", generates a JSON Schema for a tool argument so an LLM can read it, and says Symfony AI itself does not validate that schema. That page is about tool arguments. It is not about an HTTP body.

[Controller.md](Controller.md) already says Eleanor's outbound serializer returns a JSON string and does not implement Symfony's serializer interface. The inbound map is still `#[MapRequestPayload]`, which is Symfony's path.

## Tools

Eleanor adopts none of these packages here. API Platform is listed as a Symfony-ecosystem comparison.

| Tool | What it does | Source |
| --- | --- | --- |
| Symfony Validator | Checks constraints on a PHP value. With `#[MapRequestPayload]`, that check is the inbound DTO, before the action. | [Validation](https://symfony.com/doc/current/validation.html) |
| Symfony Serializer | Decodes JSON to an object and encodes an object to JSON. Unmapped inbound attributes are ignored unless `allow_extra_attributes` is false. | [Serializer](https://symfony.com/doc/current/serializer.html) |
| nelmio/api-doc-bundle | Writes an OpenAPI document from routes, PHP attributes (including Swagger-PHP), serializer metadata, and validator constraints, and can serve a documentation UI. `#[Model]` turns a PHP class into a schema. With `use_validation_groups`, a constraint group becomes `required` in that schema. The page describes documentation. | [NelmioApiDocBundle](https://symfony.com/bundles/NelmioApiDocBundle/current/index.html) |
| zircote/swagger-php | Writes an OpenAPI 3.0, 3.1, or 3.2 document from PHP attributes. The README shows generation to YAML or JSON. | [swagger-php README](https://github.com/zircote/swagger-php/blob/master/README.md) |
| api-platform/core | Writes OpenAPI and JSON Schema from PHP metadata. Validates data the client sends, with the Symfony Validator by default, and returns 422. `assertMatchesJsonSchema()` checks a response in a PHPUnit test. | [Validation](https://api-platform.com/docs/v4.4/symfony/validation/), [JSON Schema](https://api-platform.com/docs/core/json-schema/) |
| justinrainbow/json-schema | Validates a decoded JSON value against a schema. The README lists Draft-3, Draft-4, Draft-6, Draft-7, and Draft 2019-09, and says newer drafts might be unsupported. The caller supplies the document. | [json-schema README](https://github.com/jsonrainbow/json-schema/blob/master/README.md) |
| opis/json-schema | Validates a JSON document against draft 2020-12, 2019-09, 07, or 06. The README names configuration files and data sent to an endpoint as the documents. | [opis/json-schema README](https://github.com/opis/json-schema/blob/master/README.md) |
| cebe/php-openapi | Reads and writes an OpenAPI 3.0 file. The CLI `validate` command checks that file against the OpenAPI 3.0 schema. | [php-openapi README](https://github.com/cebe/php-openapi/blob/master/README.md) |
| league/openapi-psr7-validator | Validates a PSR-7 request and a PSR-7 response against an OpenAPI 3.0 file. It can sit in middleware. It reads the file with cebe/php-openapi. | [openapi-psr7-validator README](https://github.com/thephpleague/openapi-psr7-validator/blob/master/README.md) |

Direction of the source, from those pages:

- Nelmio, swagger-php, and API Platform read PHP attributes or metadata and write the OpenAPI or JSON Schema document.
- justinrainbow and opis read a schema and check an instance. They do not write the HTTP documentation.
- cebe reads and writes the OpenAPI file and checks the file itself.
- league reads an OpenAPI 3.0 file and checks HTTP messages against it.

Nelmio's current page still points the reader at the OpenAPI 3.0 specification for the fields of `documentation`. swagger-php's README says it can emit 3.1. justinrainbow's README does not list draft 2020-12. opis does. cebe and league say OpenAPI 3.0.x.

## Real contracts

[github/rest-api-description](https://github.com/github/rest-api-description) publishes OpenAPI descriptions of GitHub's REST API. The README says the `descriptions` folder is the 3.0 description and `descriptions-next` is the 3.1 description. It says the repository is kept up to date with the description used to validate GitHub API requests and to power contract tests, and that pull requests which edit the description artifacts are not accepted. [GitHub's docs](https://docs.github.com/en/rest/about-the-rest-api/about-the-openapi-description-for-the-rest-api), "Using the GitHub OpenAPI description", say the description is used to generate the Octokit SDKs and the REST reference, and that a reader can use it to generate libraries, to validate and test an integration, and to explore the API. Those pages state request validation and contract tests. They do not state that a response body is checked against the description before it is sent.

[stripe/openapi](https://github.com/stripe/openapi) publishes OpenAPI files for Stripe's API. The README says the files are generated by a custom closed-source generator, and that they are for generating SDKs or client libraries. [Stripe's changelog](https://docs.stripe.com/changelog/clover/2026-01-28/openapi-with-v2-apis) says third-party SDK authors use the files for code generation. Neither page states whether Stripe's servers validate a request or a response against the published file.

[The Kubernetes API](https://kubernetes.io/docs/concepts/overview/kubernetes-api/), "OpenAPI interface definition", says each cluster publishes OpenAPI v2 and v3 for the resources it serves. It warns that the validation rules in those schemas may not be complete, and that more validation runs in the API server. [API Concepts, Field validation](https://kubernetes.io/docs/reference/using-api/api-concepts/#field-validation) says the API server drops unrecognized fields from a submitted object by default, and that `POST`, `PUT`, and `PATCH` can warn or, with `fieldValidation=Strict`, reject unknown or duplicate fields with 400. Those pages state request validation on the server. They do not state that a response body is rejected for failing the published schema.

[Microsoft Azure REST API Guidelines](https://github.com/microsoft/api-guidelines/blob/vNext/azure/Guidelines.md) are a company style guide for Azure services. They are a choice. One rule says to fail the operation with 400 when the request is improperly formed or when any JSON field name or value is not fully understood by that version of the service. That choice rejects an unknown request field. [Types.md](Types.md) already ignores unknown request fields, and this session leaves that in place. The same guide says to publish an OpenAPI description for documentation and SDKs. It does not say to reject a response because it failed a schema at runtime.

## What Eleanor would declare

These are the shapes a schema would carry. They are not a file this session commits.

The request body, `application/json`, is a JSON object. `url` is a string. `required` lists `url`, so a missing `url` fails. [JSON Schema Validation, type](https://json-schema.org/draft/2020-12/json-schema-validation.html#section-6.1.1) accepts any value of that type, so an empty string is a string. `format` stays unset: [OpenAPI 3.1, Data Types](https://spec.openapis.org/oas/v3.1.2.html#data-types) says `format` is a non-validating annotation by default, and [Types.md](Types.md) sends every string to `UrlGuard`. A body that is not JSON never becomes an instance. That failure stays the parse in front of the schema. A body that is JSON and is not an object fails `type: object`.

Unknown request properties stay valid. The schema omits `additionalProperties`, which [Core](https://json-schema.org/draft/2020-12/json-schema-core.html#section-10.3.2.3) treats as an empty schema, so those properties validate. It does not set `additionalProperties` to `false`, and it does not set `unevaluatedProperties` to `false`. Symfony's deserializer then ignores the unmapped names, which is the default in [Deserializing an Object](https://symfony.com/doc/current/serializer.html#deserializing-an-object). The schema accepts them. The mapper drops them.

```json
{
  "type": "object",
  "properties": {
    "url": { "type": "string" }
  },
  "required": ["url"]
}
```

The success body, `application/json`, for 200 and for 201, is an object with `url` and `title`, both strings, both required. `title` has no `minLength`, so `""` validates. [Types.md](Types.md) says a later response field does not break a client, because the client ignores fields it does not know. The success schema omits `additionalProperties` for that reason. A contract test that wants a closed list sets `additionalProperties` to `false` in that test's copy.

```json
{
  "type": "object",
  "properties": {
    "url": { "type": "string" },
    "title": { "type": "string" }
  },
  "required": ["url", "title"]
}
```

The error document is `application/problem+json`. The members Eleanor sends are `status` (integer), `cause` (string), `title` (string), and `detail` (string), all required in Eleanor's document. `status` and `title` and `detail` are the RFC 9457 members of those names. `cause` is an extension member. The non-normative appendix schema also allows `type` and `instance` and does not require any member. Eleanor's example in [Types.md](Types.md) does not carry `type` or `instance`. The schema omits `additionalProperties: false`, so a later extension member stays valid, which matches the RFC rule that clients ignore extension members they do not recognize.

```json
{
  "type": "object",
  "properties": {
    "status": { "type": "integer" },
    "cause": { "type": "string" },
    "title": { "type": "string" },
    "detail": { "type": "string" }
  },
  "required": ["status", "cause", "title", "detail"]
}
```

## Research direction

The inbound check stays on the request path that already exists. `#[MapRequestPayload]` maps the JSON onto the body DTO, the Validator checks that object, and `__invoke` runs after that. `validationFailedStatusCode` is 400. Unknown properties stay accepted by the schema above and ignored by the deserializer.

The success object and the problem document are schemas for the people and the models who read the contract, and for a contract check that runs with the tests. Symfony's controller and serializer pages describe encoding a PHP value as JSON. GitHub uses its description to validate requests and to power contract tests, and to generate docs and SDKs. Stripe publishes a generated spec for client and SDK generation. Kubernetes validates submitted objects on the API server and publishes OpenAPI for clients. The outbound schema sits with the docs and with that contract check.

The PHP types in [Types.md](Types.md) are the place the fields live: constraints on the request DTO, serialization attributes on the response DTOs. When a tool is chosen, it writes the OpenAPI or JSON Schema document from those types, in the direction Nelmio, swagger-php, and API Platform document. A spec adopts the tool. This session does not.
