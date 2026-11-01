<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Probe;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Probe\ImportResult;

/**
 * Proves an import exit is an error only when the written summary is missing.
 */
final class ImportResultTest extends TestCase
{
    public function test_exit_one_with_written_summary_has_no_error(): void
    {
        self::assertNull(ImportResult::error(1, "Written 1\n"));
    }

    public function test_exit_zero_with_written_summary_has_no_error(): void
    {
        self::assertNull(ImportResult::error(0, "ok\nWritten 2\n"));
    }

    public function test_other_exit_without_written_summary_is_one_sentence(): void
    {
        $this->assertSentence(ImportResult::error(2, ''));
    }

    public function test_exit_zero_or_one_without_written_summary_is_one_sentence(): void
    {
        $this->assertSentence(ImportResult::error(0, "failed\n"));
        $this->assertSentence(ImportResult::error(1, ''));
    }

    public function test_missing_exit_is_one_sentence(): void
    {
        $this->assertSentence(ImportResult::error(null, "Written 1\n"));
    }

    private function assertSentence(?string $error): void
    {
        self::assertIsString($error);
        self::assertMatchesRegularExpression('/^[^.].+\.$/', $error);
        self::assertStringNotContainsString("\n", $error);
    }
}
