<?php

declare(strict_types=1);

namespace Yumo\LogRead\HttpFetcher;

/**
 * Bounded result of a successful `HttpFetcher::fetch()` call.
 *
 * `$body` is opaque bytes: it may not be UTF-8 and its declared charset (if any) lives
 * only in `$contentType`. Decoding into UTF-8 is `EncodingNormalizer`'s job, not this type's.
 */
final readonly class FetchedPage
{
    /**
     * @param string $body Raw decoded response bytes, no larger than the policy's `maxBytes`
     * @param string $contentType Full `Content-Type` header value, including any `charset` parameter
     * @param int $statusCode HTTP status code; always `2xx` on a successful fetch
     * @param string $requestUri URI that was requested, copied from `SafeFetchTarget::$requestUri`
     */
    public function __construct(
        public string $body,
        public string $contentType,
        public int $statusCode,
        public string $requestUri,
    ) {
    }
}
