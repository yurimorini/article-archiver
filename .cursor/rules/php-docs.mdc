---
description: PHPDoc for classes, properties, and methods; PHPStan types when native typing is not enough
globs: src/**/*.php,tests/**/*.php
alwaysApply: false
---

# PHP documentation

Write PHPDoc in English. Do not narrate what the code already says.

## Voice

Write complete sentences. Be concise, not telegraphic: do not drop articles or glue checks into a comma list as if the reader already knows the design.

Each description must make sense on its own. Do not assume the reader knows a surrounding pipeline, orchestrator, or later feature. Name another type only when this API actually uses it.

Do not describe a class by its place in a larger workflow (`First step`, `First pipeline stage`, `used by HttpFetcher`) unless that workflow is this type's public contract. An isolated class has no "step number".

Expand jargon the first time it appears in the file (SSRF, DNS pinning, LDH).

```php
// ❌ BAD — assumes a pipeline this class does not own
/**
 * First pipeline stage: parse an untrusted URL into pin-ready fetch coordinates.
 */

// ✅ GOOD — the class is understandable without the rest of the product
/**
 * Checks an untrusted URL string and returns a trusted fetch plan, or throws.
 *
 * `$result = (new UrlGuard())->guard($raw);`
 */
```

## Classes

Every class needs a description of what it is for and how to use it. Add a short usage example when construction or the public flow is not obvious from method names.

## Properties

Document every property (public and private): what it holds and why it exists.

## Public methods

Every public method needs a description of what it does. Add how it does it only when that is not obvious from the body or name.

Use `@param`, `@return`, and `@throws` for expected types and failures. Skip tags that only repeat native types (`string $raw`, `: GuardResult`).

## Private methods

Describe the helper’s role in the surrounding operation. Skip the docblock when the method is short and the name already makes the role clear.

## PHPStan

Prefer native types. Add `@param`, `@return`, `@var`, or `@phpstan-*` only when native types cannot express the shape (generics, `list<T>`, callables, array shapes).

## Tests

Test methods with speaking names (`testGuardRejectsEmptyString`) need no description. Document test helpers: what they set up or assert, and why. Same PHPStan rule as production.

## Example

```php
/**
 * Checks an untrusted URL string and returns a trusted fetch plan, or throws.
 *
 * `$result = (new UrlGuard())->guard($raw);`
 */
final class UrlGuard
{
    /** Validator that resolves the hostname and rejects non-public IP addresses. */
    private SsrfUrlValidator $validator;

    /**
     * Parses `$raw` and returns a trusted fetch plan. This method does not perform the HTTP request.
     *
     * @throws UrlGuardException When the string cannot be parsed, local rules reject it, or DNS/SSRF checks fail
     */
    public function guard(string $raw): GuardResult { /* ... */ }

    /**
     * Returns whether the last hostname label is numeric (for example `example.1`).
     * That form is treated as a literal IP address and is rejected by policy.
     */
    private function endsInNumber(string $host): bool { /* non-obvious rule */ }

    private function isLiteralIpHost(string $host): bool { /* skip: short and clear */ }
}
```
