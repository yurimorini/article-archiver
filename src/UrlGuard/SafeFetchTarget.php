<?php

declare(strict_types=1);

namespace Yumo\LogRead\UrlGuard;

/**
 * Connection details for one HTTP request after the URL has been checked.
 *
 * The request URL still uses the hostname, so TLS and the `Host` header stay correct.
 * The IP addresses that were already checked are stored separately so the HTTP client
 * can tell cURL to connect to those addresses (`CURLOPT_RESOLVE`) instead of looking
 * the hostname up again.
 *
 * Callers normally obtain an instance from `UrlGuard::guard()`. Tests may construct
 * one directly as a fixture.
 */
final class SafeFetchTarget
{
    /**
     * Records the request URL, hostname, port, and already-checked public IP addresses.
     *
     * @param list<string> $ips Public IP addresses already accepted as safe to connect to; used only to pin DNS
     */
    public function __construct(
        /** URL the HTTP client should request (hostname in the URL, not a raw IP). */
        public readonly string $requestUri,
        /** Hostname used for TLS, the `Host` header, and DNS pin entries. */
        public readonly string $host,
        /** TCP port taken from the URL, or `443` / `80` when the URL omitted it. */
        public readonly int $port,
        public readonly array $ips,
    ) {
    }

    /**
     * Returns one cURL `CURLOPT_RESOLVE` string for this host and port, with every
     * already-checked IP in the address slot, separated by commas.
     *
     * libcurl treats later `HOST:PORT:ADDRESS` entries as replacements for the same
     * host and port, so one string per IP would keep only the last address. A single
     * `{host}:{port}:{ip1,ip2,…}` entry is the format that lets cURL try every pinned IP.
     *
     * IPv6 addresses are written without brackets in the IP slot.
     *
     * @return list<string>
     */
    public function curlResolveEntries(): array
    {
        if ($this->ips === []) {
            return [];
        }

        return [$this->host . ':' . $this->port . ':' . implode(',', $this->ips)];
    }
}
