<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Request;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Yumo\Eleanor\Request\EnrichedRequest;
use Yumo\Eleanor\Request\RequestIdProcessor;

/**
 * Proves the processor copies only the stored request id onto a log record.
 */
final class RequestIdProcessorTest extends TestCase
{
    public function test_copies_the_stored_id_onto_extra(): void
    {
        $request = Request::create('/api/archives');
        $request->attributes->set(EnrichedRequest::class, new EnrichedRequest('req-1', 'Agent', 'https://ref.example'));
        $stack = new RequestStack();
        $stack->push($request);

        $record = (new RequestIdProcessor($stack))($this->record());

        self::assertSame(['request_id' => 'req-1'], $record->extra);
        self::assertSame(['request_id' => 'from-context'], $record->context);
    }

    public function test_an_empty_stack_leaves_extra_without_the_id(): void
    {
        $record = (new RequestIdProcessor(new RequestStack()))($this->record());

        self::assertSame([], $record->extra);
    }

    public function test_a_request_without_the_attribute_leaves_extra_without_the_id(): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/api/archives'));

        $record = (new RequestIdProcessor($stack))($this->record());

        self::assertSame([], $record->extra);
    }

    /**
     * Returns a domain record whose context already holds a different request id.
     */
    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'domain', Level::Info, 'archive.written', ['request_id' => 'from-context']);
    }
}
