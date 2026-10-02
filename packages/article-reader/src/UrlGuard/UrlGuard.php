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

        $host = $this->canonicalizeHostname($host);

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

    /**
     * Returns whether `$host` is a literal IPv4 or IPv6 address rather than a DNS name.
     *
     * In a URL, IPv6 must be written in square brackets (`http://[::1]/`) because `:` also
     * separates host and port. `parse_url()` keeps those brackets on `host`, while
     * `FILTER_VALIDATE_IP` only accepts the inner address (`::1`), so they are stripped first.
     */
    private function isLiteralIpHost(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $inner = substr($host, 1, -1);

            return filter_var($inner, FILTER_VALIDATE_IP) !== false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Produces the hostname form shared by Craft's validation, the request URI, and DNS pin entries.
     *
     * A trailing dot marks an absolute DNS name but does not change its destination. Removing that
     * dot and lowercasing here prevents validation and the pinned connection from using different
     * textual forms of the same hostname.
     */
    private function canonicalizeHostname(string $host): string
    {
        $host = strtolower($host);

        return str_ends_with($host, '.') && !str_ends_with($host, '..')
            ? substr($host, 0, -1)
            : $host;
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

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * Returns whether the last hostname label is numeric (for example `example.1`).
     *
     * PHP's IP filter only accepts canonical addresses such as `127.0.0.1`. HTTP
     * clients and the WHATWG URL Standard treat a last-label-numeric host as an
     * IPv4 address instead (so `foo.127.1` becomes `127.0.0.1`). That mismatch
     * is an SSRF bypass if only dotted-quad hosts are rejected, so this form is
     * refused as a literal IP by policy.
     */
    private function endsInNumber(string $host): bool
    {
        return preg_match('/(?:^|\.)[0-9]+$/D', $host) === 1;
    }

    /**
     * Rebuilds the URL string that later code should request: lowercase scheme, `/` when the path is missing,
     * query and fragment kept when present.
     *
     * Default ports (`:80` / `:443`) are omitted. RFC 3986 treats them as the same URL as the host
     * with no port, but some servers still distinguish the two and may redirect or pick a different
     * virtual host.
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
