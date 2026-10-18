<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherError;
use Yumo\ArtcleReader\HttpFetcher\HttpFetcherException;
use Yumo\ArtcleReader\Orchestrator\OrchestratorError;
use Yumo\ArtcleReader\Orchestrator\OrchestratorException;
use Yumo\ArtcleReader\Orchestrator\PipelineNoContent;
use Yumo\ArtcleReader\UrlGuard\UrlGuardError;
use Yumo\ArtcleReader\UrlGuard\UrlGuardException;
use Yumo\Eleanor\Tests\Support\FakeArticleFetcher;
use Yumo\Eleanor\Tests\Support\PipelineFixtures;

/**
 * Proves through the kernel that every failure under /api answers JSON with a code and a fixed message.
 */
final class ErrorResponseRouteTest extends WebTestCase
{
    private const string URL = 'https://example.com/a';

    /**
     * @return iterable<string, array{\Throwable|PipelineNoContent, int, string, string}>
     */
    public static function archiveFailureProvider(): iterable
    {
        yield 'url_syntax' => [self::urlGuard(UrlGuardError::Syntax), 400, 'url_syntax', 'The URL is not valid.'];
        yield 'url_policy' => [self::urlGuard(UrlGuardError::Policy), 400, 'url_policy', 'The URL is not allowed.'];
        yield 'url_rejected' => [self::urlGuard(UrlGuardError::Rejected), 400, 'url_rejected', 'The URL was refused.'];
        yield 'extract_no_content' => [PipelineFixtures::noArticle(), 422, 'extract_no_content', 'The page has no article.'];
        yield 'sanitized_empty' => [PipelineFixtures::noContent(), 422, 'sanitized_empty', 'The article is empty after purification.'];
        yield 'redirect' => [self::fetch(new HttpFetcherException(HttpFetcherError::Redirect, statusCode: 301)), 422, 'redirect', 'The page redirects. Send the final URL.'];
        yield 'content_type' => [self::fetch(new HttpFetcherException(HttpFetcherError::ContentType)), 422, 'content_type', 'The page is not HTML.'];
        yield 'body_too_large' => [self::fetch(new HttpFetcherException(HttpFetcherError::BodyTooLarge)), 422, 'body_too_large', 'The page is larger than the size limit.'];
        yield 'transport' => [self::fetch(new HttpFetcherException(HttpFetcherError::Transport)), 502, 'transport', "The page's site could not be reached."];
        yield 'upstream_status' => [self::fetch(new HttpFetcherException(HttpFetcherError::HttpStatus, statusCode: 503)), 502, 'upstream_status', "The page's site answered with status 503."];
        yield 'encoding' => [new OrchestratorException(OrchestratorError::Encoding), 500, 'encoding', "The page's character encoding could not be read."];
        yield 'extract' => [new OrchestratorException(OrchestratorError::Extract), 500, 'extract', 'The article could not be extracted.'];
        yield 'sanitize' => [new OrchestratorException(OrchestratorError::Sanitize), 500, 'sanitize', 'The article could not be purified.'];
        yield 'unexpected' => [new OrchestratorException(OrchestratorError::Unexpected), 500, 'unexpected', 'Something went wrong on the server.'];
    }

    #[DataProvider('archiveFailureProvider')]
    public function test_archive_failure_answers_its_code_and_message(
        \Throwable|PipelineNoContent $outcome,
        int $status,
        string $code,
        string $message,
    ): void {
        $client = static::createClient();
        if ($outcome instanceof \Throwable) {
            $this->fetcher()->willThrow(self::URL, $outcome);
        } else {
            $this->fetcher()->willReturn(self::URL, $outcome);
        }

        $this->postUrl($client, self::URL);

        $this->assertErrorResponse($client, $status, $code, $message);
    }

    /**
     * @return iterable<string, array{array<string, string>, string, int, string, string}>
     */
    public static function refusedRequestProvider(): iterable
    {
        $json = ['CONTENT_TYPE' => 'application/json'];
        $invalidJson = 'The request body is not valid JSON.';
        $invalidBody = 'The request body must be a JSON object with a string url.';
        $mediaType = 'The request must be sent as application/json.';

        yield 'malformed json' => [$json, '{', 400, 'invalid_json', $invalidJson];
        yield 'empty body' => [$json, '', 400, 'invalid_json', $invalidJson];
        yield 'missing url' => [$json, '{}', 400, 'invalid_body', $invalidBody];
        yield 'url null' => [$json, '{"url":null}', 400, 'invalid_body', $invalidBody];
        yield 'url not a string' => [$json, '{"url":1}', 400, 'invalid_body', $invalidBody];
        yield 'json array' => [$json, '[]', 400, 'invalid_body', $invalidBody];
        yield 'json null' => [$json, 'null', 400, 'invalid_body', $invalidBody];
        yield 'json number' => [$json, '1', 400, 'invalid_body', $invalidBody];
        yield 'json string' => [$json, '"x"', 400, 'invalid_body', $invalidBody];
        yield 'missing content type' => [[], '{"url":"https://example.com/a"}', 415, 'unsupported_media_type', $mediaType];
        yield 'text plain' => [['CONTENT_TYPE' => 'text/plain'], 'not-json', 415, 'unsupported_media_type', $mediaType];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('refusedRequestProvider')]
    public function test_refused_request_answers_its_code_and_message(
        array $server,
        string $content,
        int $status,
        string $code,
        string $message,
    ): void {
        $client = static::createClient();
        $client->request('POST', '/api/archives', [], [], $server, $content);

        $this->assertErrorResponse($client, $status, $code, $message);
        self::assertSame(0, $this->fetcher()->fetchCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readMethodProvider(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
    }

    #[DataProvider('readMethodProvider')]
    public function test_get_and_head_are_405_with_allow(string $method): void
    {
        $client = static::createClient();
        $client->request($method, '/api/archives');

        $response = $client->getResponse();
        self::assertSame(405, $response->getStatusCode());
        self::assertStringContainsString('POST', (string) $response->headers->get('Allow'));
        self::assertResponseHeaderSame('content-type', 'application/json');

        if ($method === 'HEAD') {
            self::assertSame('', $response->getContent());

            return;
        }

        self::assertSame(
            ['code' => 'method_not_allowed', 'message' => 'The method is not allowed for this path.'],
            $this->decodedBody($client),
        );
    }

    public function test_unknown_api_path_is_404_not_found(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/nothing');

        $this->assertErrorResponse($client, 404, 'not_found', 'The path does not exist.');
    }

    public function test_a_failure_reaching_the_action_carries_the_request_id(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/archives', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        self::assertResponseStatusCodeSame(400);
        self::assertNotSame('', (string) $client->getResponse()->headers->get('X-Request-Id'));
    }

    public function test_unexpected_error_answers_the_fixed_sentence(): void
    {
        $client = static::createClient();
        $this->fetcher()->willThrow(self::URL, new \Error('/etc/secret'));

        $this->postUrl($client, self::URL);

        $this->assertErrorResponse($client, 500, 'unexpected', 'Something went wrong on the server.');
        self::assertStringNotContainsString('/etc/secret', (string) $client->getResponse()->getContent());
    }

    public function test_a_failed_post_stores_nothing_and_a_retry_fetches_again(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->fetcher()->willThrow(self::URL, self::fetch(new HttpFetcherException(HttpFetcherError::Transport)));

        $this->postUrl($client, self::URL);

        self::assertResponseStatusCodeSame(502);
        self::assertSame(1, $this->fetcher()->fetchCount(self::URL));

        $this->fetcher()->willReturn(self::URL, PipelineFixtures::success());
        $this->postUrl($client, self::URL);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $this->fetcher()->fetchCount(self::URL));
    }

    private static function urlGuard(UrlGuardError $error): OrchestratorException
    {
        return new OrchestratorException(OrchestratorError::UrlGuard, previous: new UrlGuardException($error));
    }

    private static function fetch(HttpFetcherException $previous): OrchestratorException
    {
        return new OrchestratorException(OrchestratorError::Fetch, previous: $previous);
    }

    private function assertErrorResponse(KernelBrowser $client, int $status, string $code, string $message): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(['code' => $code, 'message' => $message], $this->decodedBody($client));
    }

    /**
     * @return mixed the decoded JSON body
     */
    private function decodedBody(KernelBrowser $client): mixed
    {
        return json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Reads the fake fetcher from the test container.
     *
     * A test that posts twice must call `$client->disableReboot()` first, or the fake and the shelf are lost.
     */
    private function fetcher(): FakeArticleFetcher
    {
        $fetcher = static::getContainer()->get(FakeArticleFetcher::class);
        self::assertInstanceOf(FakeArticleFetcher::class, $fetcher);

        return $fetcher;
    }

    private function postUrl(KernelBrowser $client, string $url): void
    {
        $client->request(
            'POST',
            '/api/archives',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['url' => $url], JSON_THROW_ON_ERROR),
        );
    }
}
