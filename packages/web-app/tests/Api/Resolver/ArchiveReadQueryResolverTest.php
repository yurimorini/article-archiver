<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Api\Resolver;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Yumo\Eleanor\Api\Dto\ArchiveReadQuery;
use Yumo\Eleanor\Api\Dto\ListArchivesQuery;
use Yumo\Eleanor\Api\Resolver\ArchiveReadQueryResolver;
use Yumo\Eleanor\Archive\InvalidArchiveQuery;

/**
 * Proves that the read-query resolver keeps `includeContent` as text and ignores every other key.
 */
final class ArchiveReadQueryResolverTest extends TestCase
{
    private ArchiveReadQueryResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ArchiveReadQueryResolver();
    }

    public function test_maps_an_absent_include_content_to_null(): void
    {
        $resolved = $this->resolver->resolve(
            Request::create('/api/archives/1', 'GET'),
            $this->argument(),
        );

        self::assertEquals([new ArchiveReadQuery(null)], $resolved);
    }

    public function test_reads_the_string_true(): void
    {
        $resolved = $this->resolver->resolve(
            Request::create('/api/archives/1', 'GET', ['includeContent' => 'true']),
            $this->argument(),
        );

        self::assertEquals([new ArchiveReadQuery('true')], $resolved);
    }

    public function test_ignores_sort(): void
    {
        $resolved = $this->resolver->resolve(
            Request::create('/api/archives/1', 'GET', ['sort' => 'title', 'includeContent' => 'true']),
            $this->argument(),
        );

        self::assertEquals([new ArchiveReadQuery('true')], $resolved);
    }

    public function test_keeps_a_string_other_than_true(): void
    {
        $resolved = $this->resolver->resolve(
            Request::create('/api/archives/1', 'GET', ['includeContent' => 'false']),
            $this->argument(),
        );

        self::assertEquals([new ArchiveReadQuery('false')], $resolved);
    }

    public function test_rejects_a_repeated_include_content(): void
    {
        $this->expectException(InvalidArchiveQuery::class);

        $this->resolver->resolve(
            Request::create('/api/archives/1', 'GET', ['includeContent' => ['true', 'true']]),
            $this->argument(),
        );
    }

    public function test_rejects_a_non_string_include_content(): void
    {
        $this->expectException(InvalidArchiveQuery::class);

        $this->resolver->resolve(
            Request::create('/api/archives/1', 'GET', ['includeContent' => true]),
            $this->argument(),
        );
    }

    public function test_returns_nothing_for_another_argument_type(): void
    {
        $resolved = $this->resolver->resolve(
            Request::create('/api/archives/1', 'GET', ['includeContent' => ['true', 'true']]),
            $this->argument(ListArchivesQuery::class),
        );

        self::assertSame([], $resolved);
    }

    private function argument(string $type = ArchiveReadQuery::class): ArgumentMetadata
    {
        return new ArgumentMetadata('query', $type, false, false, null);
    }
}
