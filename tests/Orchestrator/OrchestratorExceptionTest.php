<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\Orchestrator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\Orchestrator\OrchestratorError;
use Yumo\LogRead\Orchestrator\OrchestratorException;

final class OrchestratorExceptionTest extends TestCase
{
    #[DataProvider('defaultMessages')]
    public function test_default_message_when_message_empty(OrchestratorError $error, string $expected): void
    {
        $exception = new OrchestratorException($error);

        self::assertSame($expected, $exception->getMessage());
        self::assertSame($error, $exception->error);
        self::assertNull($exception->getPrevious());
    }

    /**
     * @return array<string, array{OrchestratorError, string}>
     */
    public static function defaultMessages(): array
    {
        return [
            'url guard' => [OrchestratorError::UrlGuard, 'URL guard rejected the input URL'],
            'fetch' => [OrchestratorError::Fetch, 'HTTP fetch failed'],
            'encoding' => [OrchestratorError::Encoding, 'Character encoding normalization failed'],
            'extract' => [OrchestratorError::Extract, 'Article extraction failed'],
            'sanitize' => [OrchestratorError::Sanitize, 'HTML sanitization failed'],
            'unexpected' => [OrchestratorError::Unexpected, 'Article pipeline failed unexpectedly'],
        ];
    }

    public function test_custom_message_wins_and_previous_is_kept(): void
    {
        $previous = new \RuntimeException('stage boom');
        $exception = new OrchestratorException(OrchestratorError::Fetch, 'could not download', $previous);

        self::assertSame('could not download', $exception->getMessage());
        self::assertSame(OrchestratorError::Fetch, $exception->error);
        self::assertSame($previous, $exception->getPrevious());
    }
}
