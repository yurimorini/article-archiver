<?php

declare(strict_types=1);

namespace Yumo\LogRead\UrlGuard;

/**
 * Normalize an untrusted URL string and produce pin-ready fetch coordinates.
 */
final class UrlGuard
{
    private SsrfUrlValidator $validator;

    public function __construct(?SsrfUrlValidator $validator = null)
    {
        $this->validator = $validator ?? new CraftCmsSsrfUrlValidator();
    }

    /**
     * @throws UrlGuardException
     */
    public function guard(string $raw): GuardResult
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new UrlGuardException(UrlGuardError::Syntax);
        }

        try {
            $parts = parse_url($trimmed);
        } catch (\ValueError) {
            throw new UrlGuardException(UrlGuardError::Syntax);
        }

        if ($parts === false) {
            throw new UrlGuardException(UrlGuardError::Syntax);
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = $parts['host'] ?? '';

        if ($scheme === '' || ($host === '' && ($scheme === 'http' || $scheme === 'https'))) {
            throw new UrlGuardException(UrlGuardError::Syntax);
        }

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new UrlGuardException(
                UrlGuardError::Policy,
                'URL scheme or credentials are not allowed',
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UrlGuardException(
                UrlGuardError::Policy,
                'URL scheme or credentials are not allowed',
            );
        }

        if ($this->isLiteralIpHost($host)) {
            throw new UrlGuardException(
                UrlGuardError::Policy,
                'Literal IP hosts are not allowed',
            );
        }

        if (!$this->isAsciiLdhHostname($host)) {
            throw new UrlGuardException(
                UrlGuardError::Policy,
                'Non-ASCII or IDN hosts are not allowed',
            );
        }

        $host = strtolower($host);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $requestUri = $this->buildRequestUri($scheme, $host, $port, $parts);

        try {
            $ips = $this->validator->validate($requestUri);
        } catch (\Throwable $e) {
            throw new UrlGuardException(UrlGuardError::Rejected, '', $e);
        }

        if ($ips === []) {
            throw new UrlGuardException(UrlGuardError::Rejected, 'SSRF validator returned no IPs');
        }

        /** @var list<string> $ips */
        $safe = new SafeFetchTarget($requestUri, $host, $port, $ips);

        return new GuardResult($safe, $raw);
    }

    private function isLiteralIpHost(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $inner = substr($host, 1, -1);

            return filter_var($inner, FILTER_VALIDATE_IP) !== false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    private function isAsciiLdhHostname(string $host): bool
    {
        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            return false;
        }

        $normalized = strtolower($host);
        if ($normalized === '' || str_contains($normalized, '..')) {
            return false;
        }

        return preg_match(
            '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/',
            $normalized,
        ) === 1;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function buildRequestUri(string $scheme, string $host, int $port, array $parts): string
    {
        $authority = $host;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        if ($port !== $defaultPort) {
            $authority .= ':' . $port;
        }

        $path = $parts['path'] ?? '';
        if ($path === '') {
            $path = '/';
        }

        $uri = $scheme . '://' . $authority . $path;
        if (isset($parts['query'])) {
            $uri .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $uri .= '#' . $parts['fragment'];
        }

        return $uri;
    }
}
