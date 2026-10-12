<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Request;

/**
 * Keeps a valid request id or mints a UUID version 4 from random bytes.
 */
final class UuidRequestIdFactory implements RequestIdFactory
{
    /**
     * Keeps $value when it matches the id rule. Otherwise returns a lowercase UUID version 4.
     */
    public function fromHeader(?string $value): string
    {
        if ($value !== null && preg_match('/^[A-Za-z0-9._-]{1,128}\z/', $value) === 1) {
            return $value;
        }

        return $this->mint();
    }

    private function mint(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
