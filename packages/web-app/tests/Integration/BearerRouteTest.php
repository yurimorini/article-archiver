<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Yumo\Eleanor\Tests\Support\ArchiveDirectories;
use Yumo\Eleanor\Tests\Support\FakeArticleFetcher;
use Yumo\Eleanor\Tests\Support\PipelineFixtures;

/**
 * Proves through the kernel that a matched `/api` route needs the configured bearer secret.
 */
final class BearerRouteTest extends WebTestCase
{
    private const string UNAUTHENTICATED = '{"code":"unauthenticated","message":"The request is not authenticated."}';

    protected function setUp(): void
    {
        ArchiveDirectories::isolate();
        parent::setUp();
    }

    public function test_a_missing_header_is_401(): void
    {
        $client = static::createClient();

        $this->post($client, []);

        $this->assertUnauthenticated($client);
        self::assertResponseNotHasHeader('WWW-Authenticate');
        self::assertResponseNotHasHeader('X-Request-Id');
    }

    public function test_a_wrong_secret_is_the_same_401(): void
    {
        $client = static::createClient();

        $this->post($client, ['HTTP_AUTHORIZATION' => 'Bearer wrong']);

        $this->assertUnauthenticated($client);
    }

    public function test_another_scheme_is_the_same_401(): void
    {
        $client = static::createClient();

        $this->post($client, ['HTTP_AUTHORIZATION' => 'Basic test-bearer-secret']);

        $this->assertUnauthenticated($client);
    }

    public function test_an_empty_bearer_value_is_the_same_401(): void
    {
        $client = static::createClient();

        $this->post($client, ['HTTP_AUTHORIZATION' => 'Bearer']);

        $this->assertUnauthenticated($client);
    }

    public function test_a_trailing_space_is_401(): void
    {
        $client = static::createClient();

        $this->post($client, ['HTTP_AUTHORIZATION' => 'Bearer test-bearer-secret ']);

        $this->assertUnauthenticated($client);
    }

    public function test_broken_json_without_a_secret_is_401(): void
    {
        $client = static::createClient();

        $this->post($client, [], '{');

        $this->assertUnauthenticated($client);
    }

    public function test_lowercase_scheme_archives(): void
    {
        $client = static::createClient();
        $fetcher = static::getContainer()->get(FakeArticleFetcher::class);
        self::assertInstanceOf(FakeArticleFetcher::class, $fetcher);
        $fetcher->willReturn('https://example.com/a', PipelineFixtures::article());

        $this->post($client, ['HTTP_AUTHORIZATION' => 'bearer test-bearer-secret']);

        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('"url":"https://example.com/a"', $client->getInternalResponse()->getContent());
        self::assertResponseNotHasHeader('Set-Cookie');
    }

    /**
     * @param array<string, string> $server
     */
    private function post(KernelBrowser $client, array $server, string $content = '{"url":"https://example.com/a"}'): void
    {
        $server['CONTENT_TYPE'] = 'application/json';
        $client->request('POST', '/api/archives', [], [], $server, $content);
    }

    private function assertUnauthenticated(KernelBrowser $client): void
    {
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(self::UNAUTHENTICATED, $client->getInternalResponse()->getContent());
    }
}
