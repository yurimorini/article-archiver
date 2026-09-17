<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\EncodingNormalizer;

use PHPUnit\Framework\TestCase;
use Yumo\LogRead\EncodingNormalizer\EncodingError;
use Yumo\LogRead\EncodingNormalizer\EncodingOutcome;
use Yumo\LogRead\EncodingNormalizer\EncodingQuality;
use Yumo\LogRead\EncodingNormalizer\Utf8Html;

final class EncodingOutcomeTest extends TestCase
{
    public function test_ok_factory_has_no_warning(): void
    {
        $html = $this->html();
        $outcome = EncodingOutcome::ok($html);

        self::assertSame(EncodingQuality::Ok, $outcome->quality);
        self::assertTrue($outcome->isOk());
        self::assertFalse($outcome->isDegraded());
        self::assertSame($html, $outcome->html);
        self::assertNull($outcome->warning);
        self::assertNull($outcome->previous);
    }

    public function test_degraded_factory_requires_warning_and_keeps_previous(): void
    {
        $html = $this->html();
        $previous = new \RuntimeException('mb refused');
        $outcome = EncodingOutcome::degraded($html, EncodingError::Conversion, $previous);

        self::assertSame(EncodingQuality::Degraded, $outcome->quality);
        self::assertTrue($outcome->isDegraded());
        self::assertFalse($outcome->isOk());
        self::assertSame($html, $outcome->html);
        self::assertSame(EncodingError::Conversion, $outcome->warning);
        self::assertSame($previous, $outcome->previous);
    }

    public function test_degraded_previous_is_optional(): void
    {
        $outcome = EncodingOutcome::degraded($this->html(), EncodingError::Undeclared);
        self::assertNull($outcome->previous);
        self::assertSame(EncodingError::Undeclared, $outcome->warning);
    }

    private function html(): Utf8Html
    {
        return new Utf8Html(
            html: '<p>ok</p>',
            sourceUrl: 'https://example.com/article',
            sourceEncoding: 'UTF-8',
        );
    }
}
