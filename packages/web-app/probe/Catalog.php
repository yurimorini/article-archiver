<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

/**
 * Names every probe step and the HTTP row for each preset.
 */
final class Catalog
{
    /**
     * Returns the write-chain step names in run order.
     *
     * @return list<string>
     */
    public static function writeChain(): array
    {
        return ['import', 'archive', 'archive-again'];
    }

    /**
     * Returns the read step names.
     *
     * @return list<string>
     */
    public static function reads(): array
    {
        return ['list', 'sql'];
    }

    /**
     * Returns the refusal step names in catalog order.
     *
     * @return list<string>
     */
    public static function refusals(): array
    {
        return [
            'no-bearer',
            'wrong-bearer',
            'invalid-json',
            'invalid-body',
            'url-policy',
            'unsupported-media-type',
            'not-found',
            'method-not-allowed',
            'invalid-query',
        ];
    }

    /**
     * Returns the HTTP row for `$name`.
     *
     * `import` and `sql` have no row.
     */
    public static function preset(string $name): Preset
    {
        return match ($name) {
            'archive', 'archive-again' => new Preset('POST', '/api/archives', 'application/json', null, 'bearer'),
            'list' => new Preset('GET', '/api/archives', null, null, 'bearer'),
            'no-bearer' => new Preset('POST', '/api/archives', 'application/json', '{"url":"https://example.com/"}', 'absent'),
            'wrong-bearer' => new Preset('POST', '/api/archives', 'application/json', '{"url":"https://example.com/"}', 'wrong'),
            'invalid-json' => new Preset('POST', '/api/archives', 'application/json', '{', 'bearer'),
            'invalid-body' => new Preset('POST', '/api/archives', 'application/json', '{}', 'bearer'),
            'url-policy' => new Preset('POST', '/api/archives', 'application/json', '{"url":"http://127.0.0.1/"}', 'bearer'),
            'unsupported-media-type' => new Preset('POST', '/api/archives', 'text/plain', 'https://example.com/', 'bearer'),
            'not-found' => new Preset('GET', '/api/no-such-route', null, null, 'bearer'),
            'method-not-allowed' => new Preset('PUT', '/api/archives', null, null, 'bearer'),
            'invalid-query' => new Preset('GET', '/api/archives?sort=created_at', null, null, 'bearer'),
            default => throw new \InvalidArgumentException('Unknown probe step: ' . $name),
        };
    }

    /**
     * Reports whether `$name` needs a drawn URL sample.
     */
    public static function needsDraw(string $name): bool
    {
        return in_array($name, ['import', 'archive', 'archive-again', 'sql'], true);
    }
}
