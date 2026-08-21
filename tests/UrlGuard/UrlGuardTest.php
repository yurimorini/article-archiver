<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\UrlGuard;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\UrlGuard\UrlGuard;
use Yumo\LogRead\UrlGuard\UrlGuardError;
use Yumo\LogRead\UrlGuard\UrlGuardException;

final class UrlGuardTest extends TestCase
{
    #[DataProvider('syntaxCases')]
    public function test_syntax_failures(string $raw): void
    {
        $fake = new FakeSsrfUrlValidator(shouldBeCalled: false);
        $guard = new UrlGuard($fake);

        try {
            $guard->guard($raw);
            self::fail('Expected UrlGuardException');
        } catch (UrlGuardException $e) {
            self::assertSame(UrlGuardError::Syntax, $e->error);
            self::assertSame(0, $fake->callCount);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function syntaxCases(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ["   \n"],
            'free text' => ['not a url'],
            'http no host' => ['http://'],
            'https no host' => ['https://'],
            'relative' => ['/article'],
            'odd authority' => ['http:///path'],
            'scheme only' => ['https:'],
        ];
    }

    #[DataProvider('policyCases')]
    public function test_policy_failures(string $raw): void
    {
        $fake = new FakeSsrfUrlValidator(shouldBeCalled: false);
        $guard = new UrlGuard($fake);

        try {
            $guard->guard($raw);
            self::fail('Expected UrlGuardException');
        } catch (UrlGuardException $e) {
            self::assertSame(UrlGuardError::Policy, $e->error);
            self::assertSame(0, $fake->callCount);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function policyCases(): array
    {
        return [
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://ftp.example.com/file'],
            'data' => ['data:text/html,hi'],
            'javascript' => ['javascript:alert(1)'],
            'user pass' => ['https://user:pass@example.com/'],
            'user only' => ['https://user@example.com/'],
            'ipv4 loopback' => ['http://127.0.0.1/'],
            'ipv4 public literal' => ['http://8.8.8.8/'],
            'ipv4 private' => ['http://10.0.0.1/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv6 literal' => ['http://[2001:db8::1]/'],
            'idn' => ['https://münchen.example/'],
        ];
    }

    #[DataProvider('successCases')]
    public function test_success(
        string $raw,
        string $expectedRequestUri,
        string $expectedHost,
        int $expectedPort,
    ): void {
        $fake = new FakeSsrfUrlValidator(['203.0.113.10']);
        $result = (new UrlGuard($fake))->guard($raw);

        self::assertSame($raw, $result->original);
        self::assertSame($expectedRequestUri, $result->safe->requestUri);
        self::assertSame($expectedHost, $result->safe->host);
        self::assertSame($expectedPort, $result->safe->port);
        self::assertSame(['203.0.113.10'], $result->safe->ips);
        self::assertSame(
            [$expectedHost . ':' . $expectedPort . ':203.0.113.10'],
            $result->safe->curlResolveEntries(),
        );
        self::assertSame(1, $fake->callCount);
    }

    /**
     * @return array<string, array{string, string, string, int}>
     */
    public static function successCases(): array
    {
        return [
            'https minimal' => ['https://example.com', 'https://example.com/', 'example.com', 443],
            'http minimal' => ['http://example.com', 'http://example.com/', 'example.com', 80],
            'path query fragment' => [
                'https://example.com/a/b?x=1#y',
                'https://example.com/a/b?x=1#y',
                'example.com',
                443,
            ],
            'explicit port' => ['https://example.com:8443/', 'https://example.com:8443/', 'example.com', 8443],
            'trim spaces' => ['  https://example.com/x  ', 'https://example.com/x', 'example.com', 443],
            'subdomain' => ['https://www.example.com', 'https://www.example.com/', 'www.example.com', 443],
            'uppercase scheme' => ['HTTPS://example.com', 'https://example.com/', 'example.com', 443],
        ];
    }

    public function test_success_multiple_ips(): void
    {
        $fake = new FakeSsrfUrlValidator(['203.0.113.10', '203.0.113.11']);
        $result = (new UrlGuard($fake))->guard('https://example.com/');

        self::assertSame(
            [
                'example.com:443:203.0.113.10',
                'example.com:443:203.0.113.11',
            ],
            $result->safe->curlResolveEntries(),
        );
    }

    public function test_rejected_maps_collaborator_throw(): void
    {
        $previous = new \RuntimeException('private IP');
        $fake = new FakeSsrfUrlValidator(throw: $previous);
        $guard = new UrlGuard($fake);

        try {
            $guard->guard('https://internal.example/');
            self::fail('Expected UrlGuardException');
        } catch (UrlGuardException $e) {
            self::assertSame(UrlGuardError::Rejected, $e->error);
            self::assertSame($previous, $e->getPrevious());
        }
    }

    public function test_rejected_empty_ip_list(): void
    {
        $fake = new FakeSsrfUrlValidator([]);
        $guard = new UrlGuard($fake);

        try {
            $guard->guard('https://example.com/');
            self::fail('Expected UrlGuardException');
        } catch (UrlGuardException $e) {
            self::assertSame(UrlGuardError::Rejected, $e->error);
        }
    }

    public function test_validator_receives_canonical_request_uri(): void
    {
        $fake = new class (['203.0.113.10']) extends FakeSsrfUrlValidator {
            public string $lastUrl = '';

            public function validate(string $url): array
            {
                $this->lastUrl = $url;

                return parent::validate($url);
            }
        };

        (new UrlGuard($fake))->guard('  HTTPS://Example.com  ');

        self::assertSame('https://example.com/', $fake->lastUrl);
    }
}
