<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\EncodingNormalizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\EncodingNormalizer\DetectedEncoding;
use Yumo\LogRead\EncodingNormalizer\EncodingError;
use Yumo\LogRead\EncodingNormalizer\EncodingSource;

final class DetectedEncodingTest extends TestCase
{
    public function test_trusted_factory_has_no_warning(): void
    {
        $detected = DetectedEncoding::trusted('UTF-8', EncodingSource::HttpHeader);

        self::assertSame('UTF-8', $detected->encoding);
        self::assertSame(EncodingSource::HttpHeader, $detected->source);
        self::assertNull($detected->warning);
        self::assertFalse($detected->isWeak());
    }

    public function test_weak_factory_requires_warning(): void
    {
        $detected = DetectedEncoding::weak(
            'Windows-1252',
            EncodingSource::Detect,
            EncodingError::Undeclared,
        );

        self::assertSame('Windows-1252', $detected->encoding);
        self::assertSame(EncodingSource::Detect, $detected->source);
        self::assertSame(EncodingError::Undeclared, $detected->warning);
        self::assertTrue($detected->isWeak());
    }

    #[DataProvider('emptyEncodings')]
    public function test_rejects_empty_encoding(callable $build): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('encoding must not be empty');
        $build();
    }

    /**
     * @return array<string, array{callable(): DetectedEncoding}>
     */
    public static function emptyEncodings(): array
    {
        return [
            'trusted empty' => [
                static fn (): DetectedEncoding => DetectedEncoding::trusted('', EncodingSource::HttpHeader),
            ],
            'trusted blank' => [
                static fn (): DetectedEncoding => DetectedEncoding::trusted('   ', EncodingSource::Meta),
            ],
            'weak empty' => [
                static fn (): DetectedEncoding => DetectedEncoding::weak(
                    '',
                    EncodingSource::Detect,
                    EncodingError::Undeclared,
                ),
            ],
        ];
    }
}
