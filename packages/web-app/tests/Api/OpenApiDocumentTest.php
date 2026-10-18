<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Yumo\Eleanor\Api\Error\ArchiveFailurePresenter;
use Yumo\Eleanor\Api\Error\RequestFailure;
use Yumo\Eleanor\Archive\ArchiveFailure;
use Yumo\Eleanor\Tools\OpenApiDocument;

/**
 * Proves that the committed OpenAPI document is the one the code generates and that it states the contract.
 */
final class OpenApiDocumentTest extends TestCase
{
    public function test_the_committed_document_matches_the_code(): void
    {
        $committed = file_get_contents(OpenApiDocument::committedPath());

        self::assertSame(
            OpenApiDocument::generate(),
            $committed,
            'doc/openapi.yaml is out of date. Run ./bin/composer web-app openapi.',
        );
    }

    public function test_the_openapi_version_is_3_1_0(): void
    {
        self::assertSame('3.1.0', $this->document()['openapi']);
    }

    public function test_post_archives_declares_exactly_these_statuses(): void
    {
        $statuses = array_keys($this->responses());
        sort($statuses);

        self::assertSame([200, 201, 400, 415, 422, 500, 502], $statuses);
    }

    public function test_each_status_lists_exactly_the_codes_the_translator_gives(): void
    {
        $responses = $this->responses();

        foreach ($this->expectedCodesByStatus() as $status => $expected) {
            $schema = $this->resolve($responses[$status]['content']['application/json']['schema']);
            $codes = $schema['properties']['code']['enum'];
            sort($codes);
            sort($expected);

            self::assertSame($expected, $codes, "Codes of status {$status}.");
            self::assertSame(['code', 'message'], $this->sorted($schema['required']), "Required of status {$status}.");
            self::assertSame('string', $schema['properties']['code']['type']);
            self::assertSame('string', $schema['properties']['message']['type']);
        }
    }

    public function test_request_body_requires_a_string_url(): void
    {
        $post = $this->document()['paths']['/api/archives']['post'];
        $schema = $this->resolve($post['requestBody']['content']['application/json']['schema']);

        self::assertSame(['url'], $schema['required']);
        self::assertSame('string', $schema['properties']['url']['type']);
    }

    public function test_success_body_has_only_url_and_title(): void
    {
        $responses = $this->responses();

        foreach ([200, 201] as $status) {
            $schema = $this->resolve($responses[$status]['content']['application/json']['schema']);

            self::assertSame(['title', 'url'], $this->sorted(array_keys($schema['properties'])), "Properties of {$status}.");
            self::assertSame(['title', 'url'], $this->sorted($schema['required']), "Required of {$status}.");
        }
    }

    /**
     * @return array<int, list<string>>
     */
    private function expectedCodesByStatus(): array
    {
        $presenter = new ArchiveFailurePresenter();
        $codes = [];

        foreach (ArchiveFailure::cases() as $failure) {
            $codes[$presenter->status($failure)][] = $failure->value;
        }

        foreach (RequestFailure::cases() as $failure) {
            if (\in_array($failure->status(), [400, 415], true)) {
                $codes[$failure->status()][] = $failure->value;
            }
        }

        return $codes;
    }

    /**
     * @return array<int, mixed>
     */
    private function responses(): array
    {
        return $this->document()['paths']['/api/archives']['post']['responses'];
    }

    /**
     * Follows a local `$ref` to the schema it names.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function resolve(array $schema): array
    {
        if (!isset($schema['$ref'])) {
            return $schema;
        }

        $name = substr($schema['$ref'], \strlen('#/components/schemas/'));

        return $this->document()['components']['schemas'][$name];
    }

    /**
     * @param list<int|string> $values
     *
     * @return list<int|string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $document = Yaml::parse(OpenApiDocument::generate());
        self::assertIsArray($document);

        return $document;
    }
}
