<?php

declare(strict_types=1);

namespace Yumo\LogRead\UrlGuard;

/**
 * Checks an untrusted URL string and, if it is acceptable, returns a trusted fetch plan.
 *
 * The string is not assumed to be a valid or safe URL. This class parses it, rejects
 * destinations that this library will not request (non-http(s) schemes, credentials in
 * the URL, literal IP hosts, internationalized hostnames), then asks a validator to
 * resolve the hostname and refuse private or loopback addresses (SSRF: server-side
 * request forgery). The accepted public IP addresses are stored on the result so an
 * HTTP client can connect to those IPs without looking the hostname up again.
 *
 * `$result = (new UrlGuard())->guard($raw);`
 *
 * Unit tests can pass a stub validator to avoid real DNS lookups:
 * `new UrlGuard($fakeValidator)`.
 */
final class UrlGuard
{
    /** Validator that resolves the hostname and rejects non-public IP addresses. */
    private SsrfUrlValidator $validator;

    /**
     * Creates a guard. When `$validator` is omitted, the default DNS and SSRF checker is used.
     * Pass a test double when you need a fixed IP list or a forced failure without network I/O.
     */
    public function __construct(?SsrfUrlValidator $validator = null)
    {
        $this->validator = $validator ?? new CraftCmsSsrfUrlValidator();
    }

    /**
     * Parses `$raw` and returns a trusted fetch plan, or throws. This method does not perform the HTTP request.
     *
     * Leading and trailing whitespace is trimmed for parsing only; the original string is preserved on the result for logging.
     *
     * @throws UrlGuardException When the string cannot be parsed (`Syntax`), it breaks local rules such as scheme or credentials (`Policy`), or DNS/SSRF checks refuse it (`Rejected`)
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

        if ($this->endsInNumber($host)) {
            throw new UrlGuardException(
                UrlGuardError::Policy,
                'Literal IP hosts are not allowed',
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

    /**
     * Returns whether `$host` uses only ASCII letters, digits, and hyphens (LDH labels).
     * Internationalized names are not converted to punycode, so they are rejected by policy.
     */
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
     * Returns whether the last hostname label is numeric (for example `example.1`).
     * That form is treated as a literal IP address and is rejected by policy.
     */
    private function endsInNumber(string $host): bool
    {
        return preg_match('/(?:^|\.)[0-9]+$/D', $host) === 1;
    }

    /**
     * Rebuilds the URL string that later code should request: lowercase scheme, `/` when the path is missing,
     * default ports omitted, query and fragment kept when present.
     *
     * @param array<string, mixed> $parts Result of `parse_url()`
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
