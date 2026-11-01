<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Probe;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Probe\Catalog;
use Yumo\Eleanor\Probe\Selection;

/**
 * Proves that the catalog names each probe step and selection picks one or the configured list.
 */
final class CatalogTest extends TestCase
{
    public function test_write_chain_and_reads_equal_the_named_lists(): void
    {
        self::assertSame(['import', 'archive', 'archive-again'], Catalog::writeChain());
        self::assertSame(['list', 'detail', 'detail-content', 'detail-html', 'sql'], Catalog::reads());
        self::assertSame([
            'no-bearer',
            'wrong-bearer',
            'invalid-json',
            'invalid-body',
            'url-policy',
            'unsupported-media-type',
            'not-found',
            'method-not-allowed',
            'invalid-query',
        ], Catalog::refusals());
    }

    public function test_preset_rows_match_the_named_fields_and_every_other_row(): void
    {
        self::assertSame('{}', Catalog::preset('invalid-body')->body);
        self::assertSame('absent', Catalog::preset('no-bearer')->authorization);
        self::assertSame('/api/archives?sort=created_at', Catalog::preset('invalid-query')->path);

        $this->assertRow('archive', 'POST', '/api/archives', 'application/json', null, 'bearer');
        $this->assertRow('archive-again', 'POST', '/api/archives', 'application/json', null, 'bearer');
        $this->assertRow('list', 'GET', '/api/archives', null, null, 'bearer');
        $this->assertRow('detail', 'GET', '/api/archives/{id}', null, null, 'bearer');
        $this->assertRow('detail-content', 'GET', '/api/archives/{id}?includeContent=true', null, null, 'bearer');
        $this->assertRow('detail-html', 'GET', '/api/archives/{id}.html', null, null, 'bearer');
        $this->assertRow('no-bearer', 'POST', '/api/archives', 'application/json', '{"url":"https://example.com/"}', 'absent');
        $this->assertRow('wrong-bearer', 'POST', '/api/archives', 'application/json', '{"url":"https://example.com/"}', 'wrong');
        $this->assertRow('invalid-json', 'POST', '/api/archives', 'application/json', '{', 'bearer');
        $this->assertRow('invalid-body', 'POST', '/api/archives', 'application/json', '{}', 'bearer');
        $this->assertRow('url-policy', 'POST', '/api/archives', 'application/json', '{"url":"http://127.0.0.1/"}', 'bearer');
        $this->assertRow('unsupported-media-type', 'POST', '/api/archives', 'text/plain', 'https://example.com/', 'bearer');
        $this->assertRow('not-found', 'GET', '/api/no-such-route', null, null, 'bearer');
        $this->assertRow('method-not-allowed', 'PUT', '/api/archives', null, null, 'bearer');
        $this->assertRow('invalid-query', 'GET', '/api/archives?sort=created_at', null, null, 'bearer');
    }

    public function test_needs_draw_is_true_for_import_and_false_for_list(): void
    {
        self::assertTrue(Catalog::needsDraw('import'));
        self::assertTrue(Catalog::needsDraw('detail'));
        self::assertTrue(Catalog::needsDraw('detail-content'));
        self::assertTrue(Catalog::needsDraw('detail-html'));
        self::assertFalse(Catalog::needsDraw('list'));
    }

    public function test_preset_rejects_import(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Catalog::preset('import');
    }

    public function test_preset_rejects_sql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Catalog::preset('sql');
    }

    public function test_preset_rejects_an_unknown_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Catalog::preset('nope');
    }

    public function test_selection_returns_the_configured_list_or_one_named_step(): void
    {
        self::assertSame(['import', 'list'], Selection::names(['import', 'list'], []));
        self::assertSame(['invalid-json'], Selection::names(['import'], ['invalid-json']));
    }

    public function test_selection_rejects_an_unknown_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Selection::names(['import'], ['nope']);
    }

    public function test_selection_rejects_two_argv_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Selection::names(['import'], ['a', 'b']);
    }

    private function assertRow(
        string $name,
        string $method,
        string $path,
        ?string $contentType,
        ?string $body,
        string $authorization,
    ): void {
        $preset = Catalog::preset($name);

        self::assertSame($method, $preset->method);
        self::assertSame($path, $preset->path);
        self::assertSame($contentType, $preset->contentType);
        self::assertSame($body, $preset->body);
        self::assertSame($authorization, $preset->authorization);
    }
}
