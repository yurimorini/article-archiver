<?php

declare(strict_types=1);

namespace Yumo\LogRead\UrlGuard;

interface SsrfUrlValidator
{
    /**
     * Check SSRF policy and resolve public IPs for pinning.
     *
     * @return list<string> Public IP addresses (non-empty on success)
     * @throws \Throwable Collaborator failure; UrlGuard maps into UrlGuardException
     */
    public function validate(string $url): array;
}
