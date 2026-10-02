<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\Orchestrator;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\Orchestrator\PipelineWarning;
use Yumo\LogRead\Orchestrator\PipelineWarningCode;

final class PipelineWarningTest extends TestCase
{
    public function test_stores_code_message_and_optional_previous(): void
    {
        $previous = new \RuntimeException('lossy');
        $warning = new PipelineWarning(
            PipelineWarningCode::EncodingDegraded,
            'Character encoding conversion to UTF-8 failed',
            $previous,
        );

        self::assertSame(PipelineWarningCode::EncodingDegraded, $warning->code);
        self::assertSame('Character encoding conversion to UTF-8 failed', $warning->message);
        self::assertSame($previous, $warning->previous);
    }

    public function test_previous_defaults_to_null(): void
    {
        $warning = new PipelineWarning(PipelineWarningCode::EncodingDegraded, 'guessed');

        self::assertNull($warning->previous);
    }
}
