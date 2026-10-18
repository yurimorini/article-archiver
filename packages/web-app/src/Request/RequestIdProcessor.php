<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Request;

use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Copies the stored request id onto each Eleanor log record.
 *
 * `$record = $processor($record);`
 */
final class RequestIdProcessor
{
    /**
     * Creates the processor from the stack that holds the current request.
     */
    public function __construct(
        /** The stack whose current request may hold an `EnrichedRequest`. */
        private readonly RequestStack $stack,
    ) {
    }

    /**
     * Sets `extra.request_id` from the current request when that request was enriched.
     *
     * No current request, or a request without `EnrichedRequest`, leaves the record unchanged.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->stack->getCurrentRequest();
        if ($request === null) {
            return $record;
        }

        $enriched = $request->attributes->get(EnrichedRequest::class);
        if (!$enriched instanceof EnrichedRequest) {
            return $record;
        }

        $record->extra['request_id'] = $enriched->requestId;

        return $record;
    }
}
