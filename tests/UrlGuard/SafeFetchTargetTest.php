<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\UrlGuard;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\UrlGuard\GuardResult;
use Yumo\LogRead\UrlGuard\SafeFetchTarget;

final class SafeFetchTargetTest extends TestCase
{
    public function test_curl_resolve_entries_one_per_ip(): void
    {
        $safe = new SafeFetchTarget(
            requestUri: 'https://example.com/a',
            host: 'example.com',
            port: 443,
            ips: ['203.0.113.10', '203.0.113.11'],
        );

        self::assertSame(
            [
                'example.com:443:203.0.113.10',
                'example.com:443:203.0.113.11',
            ],
            $safe->curlResolveEntries(),
        );
    }

    public function test_curl_resolve_entries_ipv6_bare(): void
    {
        $safe = new SafeFetchTarget(
            requestUri: 'https://example.com/',
            host: 'example.com',
            port: 443,
            ips: ['2001:db8::1'],
        );

        self::assertSame(
            ['example.com:443:2001:db8::1'],
            $safe->curlResolveEntries(),
        );
    }

    public function test_guard_result_holds_original_separately(): void
    {
        $safe = new SafeFetchTarget(
            requestUri: 'https://example.com/',
            host: 'example.com',
            port: 443,
            ips: ['203.0.113.10'],
        );
        $result = new GuardResult($safe, '  https://example.com/  ');

        self::assertSame($safe, $result->safe);
        self::assertSame('  https://example.com/  ', $result->original);
    }
}
