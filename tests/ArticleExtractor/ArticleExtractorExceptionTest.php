<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\ArticleExtractor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\ArticleExtractorError;
use Yumo\LogRead\ArticleExtractor\ArticleExtractorException;

final class ArticleExtractorExceptionTest extends TestCase
{
    #[DataProvider('defaultMessages')]
    public function test_default_message_when_message_empty(ArticleExtractorError $error, string $expected): void
    {
        $exception = new ArticleExtractorException($error);

        self::assertSame($expected, $exception->getMessage());
        self::assertSame($error, $exception->error);
        self::assertNull($exception->getPrevious());
    }

    /**
     * @return array<string, array{ArticleExtractorError, string}>
     */
    public static function defaultMessages(): array
    {
        return [
            'too large' => [
                ArticleExtractorError::TooLarge,
                'HTML document exceeds the configured element limit',
            ],
            'unexpected' => [
                ArticleExtractorError::Unexpected,
                'Article extraction failed unexpectedly',
            ],
        ];
    }

    public function test_custom_message_wins_and_previous_is_kept(): void
    {
        $previous = new \RuntimeException('vendor boom');
        $exception = new ArticleExtractorException(
            ArticleExtractorError::TooLarge,
            'DOM exceeded the cap',
            $previous,
        );

        self::assertSame('DOM exceeded the cap', $exception->getMessage());
        self::assertSame(ArticleExtractorError::TooLarge, $exception->error);
        self::assertSame($previous, $exception->getPrevious());
    }
}
