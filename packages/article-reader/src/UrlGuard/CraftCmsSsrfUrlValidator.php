<?php

declare(strict_types=1);

namespace Yumo\LogRead\UrlGuard;

use CraftCms\UrlValidator\UrlValidator;

/**
 * Default hostname and SSRF (server-side request forgery) checker, implemented with the `craftcms/url-validator` library.
 *
 * `new CraftCmsSsrfUrlValidator()` uses the system DNS resolver. In tests, pass a
 * `$dnsResolver` callable that maps a hostname to a list of IP strings so no real
 * lookup runs.
 */
final class CraftCmsSsrfUrlValidator implements SsrfUrlValidator
{
    /** Craft library instance that performs DNS lookup and public-IP policy checks. */
    private UrlValidator $inner;

    /**
     * Creates the adapter around Craft's `UrlValidator`.
     *
     * @param (callable(string): list<string>)|null $dnsResolver Optional replacement for DNS: given a hostname, return IP addresses. `null` uses the system resolver.
     */
    public function __construct(?callable $dnsResolver = null)
    {
        $this->inner = $dnsResolver === null
            ? new UrlValidator()
            : new UrlValidator($dnsResolver);
    }

    /**
     * Runs Craft's checks on `$url` and returns the public IP addresses it accepted.
     *
     * @return list<string>
     */
    public function validate(string $url): array
    {
        /** @var list<string> $ips */
        $ips = $this->inner->validate($url);

        return $ips;
    }
}
