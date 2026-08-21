<?php

declare(strict_types=1);

namespace Yumo\LogRead\UrlGuard;

use CraftCms\UrlValidator\UrlValidator;

/**
 * Production SSRF/DNS collaborator wrapping craftcms/url-validator.
 */
final class CraftCmsSsrfUrlValidator implements SsrfUrlValidator
{
    private UrlValidator $inner;

    /**
     * @param (callable(string): list<string>)|null $dnsResolver
     */
    public function __construct(?callable $dnsResolver = null)
    {
        $this->inner = $dnsResolver === null
            ? new UrlValidator()
            : new UrlValidator($dnsResolver);
    }

    /**
     * @return list<string>
     */
    public function validate(string $url): array
    {
        /** @var list<string> $ips */
        $ips = $this->inner->validate($url);

        return $ips;
    }
}
