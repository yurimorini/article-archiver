<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\ArticleExtractor;

use Psr\Log\AbstractLogger;

/**
 * This test double records PSR-3 log calls so a test can see whether Readability received a logger.
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * This value holds each log call as a level and a message, in order.
     *
     * @var list<array{0: mixed, 1: string}>
     */
    public array $records = [];

    /**
     * This method stores the log call and does not write it anywhere else.
     *
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message];
    }
}
