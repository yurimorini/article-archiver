<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\UrlGuard;

use Yumo\LogRead\UrlGuard\SsrfUrlValidator;

/**
 * Test double: returns fixed IPs or throws. No DNS.
 */
class FakeSsrfUrlValidator implements SsrfUrlValidator
{
    /**
     * @param list<string> $ips
     */
    public function __construct(
        private readonly array $ips = ['203.0.113.10'],
        private readonly ?\Throwable $throw = null,
        private readonly bool $shouldBeCalled = true,
    ) {
    }

    public int $callCount = 0;

    public function validate(string $url): array
    {
        $this->callCount++;
        if (!$this->shouldBeCalled) {
            throw new \LogicException('SsrfUrlValidator should not have been called for: ' . $url);
        }
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->ips;
    }
}
