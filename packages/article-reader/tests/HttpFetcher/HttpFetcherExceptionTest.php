<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HttpFetcher;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\HttpFetcher\HttpFetcherError;
use Yumo\LogRead\HttpFetcher\HttpFetcherException;

final class HttpFetcherExceptionTest extends TestCase
{
    #[DataProvider('defaultMessages')]
    public function test_default_message_when_message_empty(HttpFetcherError $error, string $expected): void
    {
        $e = new HttpFetcherException($error);
        self::assertSame($expected, $e->getMessage());
        self::assertSame($error, $e->error);
    }

    /**
     * @return array<string, array{HttpFetcherError, string}>
     */
    public static function defaultMessages(): array
    {
        return [
            'transport' => [HttpFetcherError::Transport, 'HTTP transport failed (timeout, connect, or network error)'],
            'http status' => [HttpFetcherError::HttpStatus, 'HTTP response status is not successful'],
            'redirect' => [HttpFetcherError::Redirect, 'HTTP redirect is not followed'],
            'content type' => [HttpFetcherError::ContentType, 'Response Content-Type is not an allowed HTML type'],
            'body too large' => [HttpFetcherError::BodyTooLarge, 'Response body exceeds the configured size limit'],
        ];
    }

    public function test_custom_message_wins_and_previous_is_kept(): void
    {
        $previous = new \RuntimeException('vendor boom');
        $e = new HttpFetcherException(HttpFetcherError::Transport, 'connect refused', $previous);
        self::assertSame('connect refused', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }
}
