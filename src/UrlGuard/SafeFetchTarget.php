<?php

declare(strict_types=1);

namespace Yumo\LogRead\UrlGuard;

/**
 * Immutable trusted coordinates for one fetch attempt.
 */
final class SafeFetchTarget
{
    /**
     * @param list<string> $ips
     */
    public function __construct(
        public readonly string $requestUri,
        public readonly string $host,
        public readonly int $port,
        public readonly array $ips,
    ) {
    }

    /**
     * @return list<string> CURLOPT_RESOLVE entries, one per IP
     */
    public function curlResolveEntries(): array
    {
        $entries = [];
        foreach ($this->ips as $ip) {
            $entries[] = $this->host . ':' . $this->port . ':' . $ip;
        }

        return $entries;
    }
}
