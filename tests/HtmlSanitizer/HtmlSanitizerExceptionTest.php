<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HtmlSanitizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizerError;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizerException;

final class HtmlSanitizerExceptionTest extends TestCase
{
    #[DataProvider('defaultMessages')]
    public function test_default_message_when_message_empty(HtmlSanitizerError $error, string $expected): void
    {
        $exception = new HtmlSanitizerException($error);

        self::assertSame($expected, $exception->getMessage());
        self::assertSame($error, $exception->error);
        self::assertNull($exception->getPrevious());
    }

    /**
     * @return array<string, array{HtmlSanitizerError, string}>
     */
    public static function defaultMessages(): array
    {
        return [
            'configuration' => [
                HtmlSanitizerError::Configuration,
                'HTML sanitizer configuration or runtime setup failed',
            ],
            'unexpected' => [
                HtmlSanitizerError::Unexpected,
                'HTML sanitization failed unexpectedly',
            ],
        ];
    }

    public function test_custom_message_wins_and_previous_is_kept(): void
    {
        $previous = new \RuntimeException('vendor boom');
        $exception = new HtmlSanitizerException(
            HtmlSanitizerError::Configuration,
            'cache directory is not usable',
            $previous,
        );

        self::assertSame('cache directory is not usable', $exception->getMessage());
        self::assertSame(HtmlSanitizerError::Configuration, $exception->error);
        self::assertSame($previous, $exception->getPrevious());
    }
}
