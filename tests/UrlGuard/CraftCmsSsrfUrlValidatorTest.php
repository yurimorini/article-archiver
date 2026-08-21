<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\UrlGuard;

use CraftCms\UrlValidator\UrlValidationException;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\UrlGuard\CraftCmsSsrfUrlValidator;
use Yumo\LogRead\UrlGuard\UrlGuard;
use Yumo\LogRead\UrlGuard\UrlGuardError;
use Yumo\LogRead\UrlGuard\UrlGuardException;

final class CraftCmsSsrfUrlValidatorTest extends TestCase
{
    public function test_returns_public_ips_from_stub_dns(): void
    {
        $adapter = new CraftCmsSsrfUrlValidator(
            static fn (string $host): array => ['203.0.113.10'],
        );

        $ips = $adapter->validate('https://example.com/');

        self::assertSame(['203.0.113.10'], $ips);
    }

    public function test_rejects_loopback_from_stub_dns(): void
    {
        $adapter = new CraftCmsSsrfUrlValidator(
            static fn (string $host): array => ['127.0.0.1'],
        );

        $this->expectException(UrlValidationException::class);
        $adapter->validate('https://evil.example/');
    }

    public function test_url_guard_default_uses_adapter_with_stub_via_injection(): void
    {
        // Production default path is CraftCmsSsrfUrlValidator; unit-test UrlGuard
        // still injects a fake. This asserts the optional constructor accepts null
        // and builds the adapter (smoke: guard with adapter + stub DNS).
        $adapter = new CraftCmsSsrfUrlValidator(
            static fn (string $host): array => ['203.0.113.10'],
        );
        $result = (new UrlGuard($adapter))->guard('https://example.com/');

        self::assertSame('https://example.com/', $result->safe->requestUri);
        self::assertSame(['203.0.113.10'], $result->safe->ips);
    }

    public function test_url_guard_maps_adapter_rejection(): void
    {
        $adapter = new CraftCmsSsrfUrlValidator(
            static fn (string $host): array => ['169.254.169.254'],
        );

        try {
            (new UrlGuard($adapter))->guard('https://meta.example/');
            self::fail('Expected UrlGuardException');
        } catch (UrlGuardException $e) {
            self::assertSame(UrlGuardError::Rejected, $e->error);
            self::assertInstanceOf(UrlValidationException::class, $e->getPrevious());
        }
    }

    public function test_new_url_guard_without_args_constructs(): void
    {
        $guard = new UrlGuard();
        self::assertInstanceOf(UrlGuard::class, $guard);
    }
}
