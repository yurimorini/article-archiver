<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Api\Resolver;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Yumo\Eleanor\Api\Dto\ListArchivesQuery;
use Yumo\Eleanor\Api\Resolver\ArchiveListServiceResolver;
use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\ArchiveListService;
use Yumo\Eleanor\Archive\ArchiveListServiceFactory;
use Yumo\Eleanor\Archive\ArchivePageQuery;
use Yumo\Eleanor\Archive\Owner;
use Yumo\Eleanor\Tests\Support\InMemory\InMemoryArchiveShelf;
use Yumo\Eleanor\Tests\Support\InMemory\InMemoryArchiveStoreFactory;
use Yumo\Eleanor\Tests\Support\SwitchableOwnerProvider;

/**
 * Proves that the resolver hands the owner's list service to an action argument and ignores every other argument.
 */
final class ArchiveListServiceResolverTest extends TestCase
{
    public function test_returns_the_service_of_the_current_owner(): void
    {
        $stores = new InMemoryArchiveStoreFactory(new InMemoryArchiveShelf());
        $ada = new Owner('ada@example.com');
        $stores->create($ada)->save(new Archive(
            'https://example.com/a',
            'Ada',
            null,
            null,
            '<p>A</p>',
            null,
            null,
        ));
        $provider = new SwitchableOwnerProvider($ada);
        $resolver = new ArchiveListServiceResolver(
            $provider,
            new ArchiveListServiceFactory($stores, new NullLogger()),
        );
        $query = ArchivePageQuery::from(null, null, null, null, 100);

        $adaPage = $this->service($resolver)->page($query);
        $provider->current = new Owner('bea@example.com');
        $beaPage = $this->service($resolver)->page($query);

        self::assertSame(1, $adaPage->total);
        self::assertSame('https://example.com/a', $adaPage->items[0]->url);
        self::assertSame(0, $beaPage->total);
        self::assertSame([], $beaPage->items);
    }

    public function test_ignores_an_argument_of_another_type(): void
    {
        $resolver = new ArchiveListServiceResolver(
            new SwitchableOwnerProvider(new Owner('ada@example.com')),
            new ArchiveListServiceFactory(new InMemoryArchiveStoreFactory(new InMemoryArchiveShelf()), new NullLogger()),
        );

        $resolved = $resolver->resolve(new Request(), new ArgumentMetadata('query', ListArchivesQuery::class, false, false, null));

        self::assertSame([], $resolved);
    }

    private function service(ArchiveListServiceResolver $resolver): ArchiveListService
    {
        $resolved = $resolver->resolve(new Request(), new ArgumentMetadata('archives', ArchiveListService::class, false, false, null));
        self::assertInstanceOf(ArchiveListService::class, $resolved[0]);

        return $resolved[0];
    }
}
