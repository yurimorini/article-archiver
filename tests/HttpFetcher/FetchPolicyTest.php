<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HttpFetcher;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\HttpFetcher\FetchPolicy;

final class FetchPolicyTest extends TestCase
{
    public function test_defaults_construct_without_error(): void
    {
        $policy = new FetchPolicy();

        self::assertSame(10, $policy->timeoutSeconds);
        self::assertSame(5, $policy->connectTimeoutSeconds);
        self::assertSame(5_000_000, $policy->maxBytes);
        self::assertSame('LogRead/0.1', $policy->userAgent);
        self::assertSame('text/html,application/xhtml+xml;q=0.9,*/*;q=0.8', $policy->accept);
        self::assertFalse($policy->debug);
    }

    #[DataProvider('invalidConstructorArgs')]
    public function test_rejects_invalid_values(callable $build, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        $build();
    }

    /**
     * @return array<string, array{callable(): FetchPolicy, string}>
     */
    public static function invalidConstructorArgs(): array
    {
        return [
            'zero timeout' => [
                static fn (): FetchPolicy => new FetchPolicy(timeoutSeconds: 0),
                'timeoutSeconds must be positive',
            ],
            'negative timeout' => [
                static fn (): FetchPolicy => new FetchPolicy(timeoutSeconds: -1),
                'timeoutSeconds must be positive',
            ],
            'zero connect timeout' => [
                static fn (): FetchPolicy => new FetchPolicy(connectTimeoutSeconds: 0),
                'connectTimeoutSeconds must be positive',
            ],
            'negative connect timeout' => [
                static fn (): FetchPolicy => new FetchPolicy(connectTimeoutSeconds: -1),
                'connectTimeoutSeconds must be positive',
            ],
            'zero maxBytes' => [
                static fn (): FetchPolicy => new FetchPolicy(maxBytes: 0),
                'maxBytes must be positive',
            ],
            'negative maxBytes' => [
                static fn (): FetchPolicy => new FetchPolicy(maxBytes: -1),
                'maxBytes must be positive',
            ],
            'empty userAgent' => [
                static fn (): FetchPolicy => new FetchPolicy(userAgent: ''),
                'userAgent must not be empty',
            ],
            'blank userAgent' => [
                static fn (): FetchPolicy => new FetchPolicy(userAgent: '   '),
                'userAgent must not be empty',
            ],
            'empty accept' => [
                static fn (): FetchPolicy => new FetchPolicy(accept: ''),
                'accept must not be empty',
            ],
        ];
    }

    public function test_debug_flag_is_settable(): void
    {
        $policy = new FetchPolicy(debug: true);
        self::assertTrue($policy->debug);
    }
}
