<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\UrlGuard;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\UrlGuard\UrlGuardError;
use Yumo\LogRead\UrlGuard\UrlGuardException;

final class UrlGuardExceptionTest extends TestCase
{
    #[DataProvider('defaultMessages')]
    public function test_default_message_when_message_empty(UrlGuardError $error, string $expected): void
    {
        $e = new UrlGuardException($error);
        self::assertSame($expected, $e->getMessage());
        self::assertSame($error, $e->error);
    }

    /**
     * @return array<string, array{UrlGuardError, string}>
     */
    public static function defaultMessages(): array
    {
        return [
            'syntax' => [UrlGuardError::Syntax, 'URL is missing, malformed, or has no host'],
            'policy' => [UrlGuardError::Policy, 'URL scheme or credentials are not allowed'],
            'rejected' => [UrlGuardError::Rejected, 'URL was rejected by SSRF/DNS validation'],
        ];
    }

    public function test_custom_message_wins_and_previous_is_kept(): void
    {
        $previous = new \RuntimeException('vendor boom');
        $e = new UrlGuardException(UrlGuardError::Rejected, 'blocked IP', $previous);
        self::assertSame('blocked IP', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }
}
