<?php

declare(strict_types=1);

namespace Yumo\LogRead\HtmlSanitizer;

/**
 * This class holds the URI schemes and the debug switch for one HTML purifier.
 *
 * Encoding, doctype, and the definition-cache directory are not fields here.
 * The purifier always reads UTF-8, and the cache path is chosen by
 * `HtmlSanitizer`.
 *
 * `$policy = new PurifyPolicy();`
 */
final readonly class PurifyPolicy
{
    /** Schemes an article URL may use. Anything else is rejected at construction. */
    private const array MVP_SCHEMES = [
        'http' => true,
        'https' => true,
        'mailto' => true,
    ];

    /**
     * This constructor stores the scheme list and the debug switch.
     *
     * @param list<string> $allowedSchemes Lowercase scheme names. Only `http`, `https`, and `mailto` are accepted.
     */
    public function __construct(
        /** This value is the URI scheme allowlist copied onto `URI.AllowedSchemes`. */
        public array $allowedSchemes = ['http', 'https', 'mailto'],
        /** This value turns off the definition cache and enables the purifier error collector. */
        public bool $debug = false,
    ) {
        if ($allowedSchemes === []) {
            throw new \InvalidArgumentException('allowedSchemes must not be empty');
        }

        foreach ($allowedSchemes as $scheme) {
            if (!is_string($scheme) || preg_match('/^[a-z][a-z0-9-]*$/', $scheme) !== 1) {
                throw new \InvalidArgumentException('allowedSchemes must be lowercase scheme names');
            }
            if (!isset(self::MVP_SCHEMES[$scheme])) {
                throw new \InvalidArgumentException('allowedSchemes only allows http, https, and mailto');
            }
        }
    }
}
