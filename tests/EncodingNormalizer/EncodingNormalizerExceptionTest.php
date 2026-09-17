<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\EncodingNormalizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\EncodingNormalizer\EncodingError;
use Yumo\LogRead\EncodingNormalizer\EncodingNormalizerException;

final class EncodingNormalizerExceptionTest extends TestCase
{
    #[DataProvider('defaultMessages')]
    public function test_default_message_when_message_empty(EncodingError $error, string $expected): void
    {
        $e = new EncodingNormalizerException($error);
        self::assertSame($expected, $e->getMessage());
        self::assertSame($error, $e->error);
    }

    /**
     * @return array<string, array{EncodingError, string}>
     */
    public static function defaultMessages(): array
    {
        return [
            'undeclared' => [
                EncodingError::Undeclared,
                'Character encoding could not be determined',
            ],
            'unsupported' => [
                EncodingError::Unsupported,
                'Character encoding is not supported for conversion',
            ],
            'conversion' => [
                EncodingError::Conversion,
                'Character encoding conversion to UTF-8 failed',
            ],
        ];
    }

    public function test_custom_message_wins_and_previous_is_kept(): void
    {
        $previous = new \RuntimeException('vendor boom');
        $e = new EncodingNormalizerException(EncodingError::Conversion, 'iconv refused', $previous);
        self::assertSame('iconv refused', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }
}
