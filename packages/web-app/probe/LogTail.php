<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

/**
 * Picks decoded log lines that belong to one request.
 */
final class LogTail
{
    /**
     * Returns the decoded JSON objects whose `extra.request_id` equals `$requestId`.
     *
     * A line that is not JSON is ignored. A JSON line with no matching id is ignored.
     *
     * @param list<string> $lines
     *
     * @return list<array<string, mixed>>
     */
    public static function forRequest(array $lines, string $requestId): array
    {
        $matched = [];
        foreach ($lines as $line) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!is_array($decoded)) {
                continue;
            }

            $extra = $decoded['extra'] ?? null;
            if (!is_array($extra)) {
                continue;
            }

            if (($extra['request_id'] ?? null) === $requestId) {
                $matched[] = $decoded;
            }
        }

        return $matched;
    }
}
