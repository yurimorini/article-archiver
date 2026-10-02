<?php

declare(strict_types=1);

namespace Yumo\LogRead\UrlGuard;

/**
 * Looks up a URL's hostname, rejects non-public addresses, and returns the public IPs to pin.
 *
 * The production implementation is `CraftCmsSsrfUrlValidator`. Tests should supply a fake
 * that returns a fixed IP list or throws, so unit tests do not perform real DNS lookups.
 */
interface SsrfUrlValidator
{
    /**
     * Resolves `$url`, refuses private, loopback, or otherwise blocked addresses, and returns the public IPs.
     *
     * Example: `validate('https://example.com/')` may return `['203.0.113.10']` when DNS
     * yields that public address. A loopback or private IP throws.
     *
     * @return list<string> Public IP addresses; must be non-empty on success
     * @throws \Throwable When lookup fails or the address is not allowed; `UrlGuard` maps this into `UrlGuardException`
     */
    public function validate(string $url): array;
}
