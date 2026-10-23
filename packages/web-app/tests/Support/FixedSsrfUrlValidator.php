<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Support;

use Yumo\ArtcleReader\UrlGuard\SsrfUrlValidator;

/**
 * Resolves every host to one public address so a command test does not use DNS.
 */
final class FixedSsrfUrlValidator implements SsrfUrlValidator
{
    /**
     * Returns a fixed public address. The URL is not looked up.
     *
     * @return list<string>
     */
    public function validate(string $url): array
    {
        return ['1.1.1.1'];
    }
}
