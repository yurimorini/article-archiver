<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Request;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the request id, User-Agent, and Referer into an enriched request.
 */
final class RequestEnricher
{
    public function __construct(
        private readonly RequestIdFactory $ids,
    ) {
    }

    /**
     * Uses the first request id only. A missing or empty User-Agent or Referer is null.
     */
    public function enrich(Request $request): EnrichedRequest
    {
        $ids = $request->headers->all('X-Request-Id');
        $first = $ids[0] ?? null;

        return new EnrichedRequest(
            $this->ids->fromHeader(is_string($first) ? $first : null),
            $this->present($request->headers->get('User-Agent')),
            $this->present($request->headers->get('Referer')),
        );
    }

    private function present(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
