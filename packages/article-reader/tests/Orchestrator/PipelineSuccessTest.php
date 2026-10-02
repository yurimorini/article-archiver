<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\Orchestrator;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\PlainText;
use Yumo\LogRead\HtmlSanitizer\SafeDocument;
use Yumo\LogRead\Orchestrator\PipelineSuccess;
use Yumo\LogRead\Orchestrator\PipelineWarning;
use Yumo\LogRead\Orchestrator\PipelineWarningCode;

final class PipelineSuccessTest extends TestCase
{
    public function test_stores_document_and_reports_clean_when_there_are_no_warnings(): void
    {
        $document = $this->document('<p>Hello</p>');
        $success = new PipelineSuccess($document);

        self::assertSame($document, $success->document);
        self::assertSame([], $success->warnings);
        self::assertFalse($success->isDegraded());
    }

    public function test_is_degraded_when_a_warning_is_present(): void
    {
        $warning = new PipelineWarning(PipelineWarningCode::EncodingDegraded, 'guessed');
        $success = new PipelineSuccess($this->document('<p>Hello</p>'), [$warning]);

        self::assertSame([$warning], $success->warnings);
        self::assertTrue($success->isDegraded());
    }

    public function test_rejects_empty_or_whitespace_html(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Successful article HTML must not be empty');

        new PipelineSuccess($this->document(" \n\t"));
    }

    private function document(string $html): SafeDocument
    {
        return new SafeDocument(
            $html,
            PlainText::fromUntrusted('Title'),
            null,
            null,
            'https://example.com/article',
        );
    }
}
