<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Api\Resolver;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Yumo\Eleanor\Api\Dto\CreateArchiveBody;
use Yumo\Eleanor\Api\Dto\ListArchivesQuery;
use Yumo\Eleanor\Api\Owner\OwnerProvider;
use Yumo\Eleanor\Api\Resolver\OwnerScopedServiceResolver;
use Yumo\Eleanor\Archive\Archive;
use Yumo\Eleanor\Archive\ArchiveListService;
use Yumo\Eleanor\Archive\ArchiveListServiceFactory;
use Yumo\Eleanor\Archive\ArchiveOutcome;
use Yumo\Eleanor\Archive\ArchivePageQuery;
use Yumo\Eleanor\Archive\ArchiveService;
use Yumo\Eleanor\Archive\ArchiveServiceFactory;
use Yumo\Eleanor\Archive\Owner;
use Yumo\Eleanor\Archive\UrlPointer;
use Yumo\Eleanor\Tests\Support\FakeArchiveLockFactory;
use Yumo\Eleanor\Tests\Support\FakeArticleFetcher;
use Yumo\Eleanor\Tests\Support\InMemory\InMemoryArchiveShelf;
use Yumo\Eleanor\Tests\Support\InMemory\InMemoryArchiveStoreFactory;
use Yumo\Eleanor\Tests\Support\PipelineFixtures;
use Yumo\Eleanor\Tests\Support\SwitchableOwnerProvider;

/**
 * Proves that the resolver hands the owner's service to an action argument and ignores every other argument.
 */
final class OwnerScopedServiceResolverTest extends TestCase
{
    private const string URL = 'https://example.com/a';

    private ArchiveServiceFactory $factory;

    private ArchiveListServiceFactory $lists;

    private SwitchableOwnerProvider $provider;

    protected function setUp(): void
    {
        $fetcher = new FakeArticleFetcher();
        $fetcher->willReturn(self::URL, PipelineFixtures::article());
        $this->factory = new ArchiveServiceFactory(
            $fetcher,
            new InMemoryArchiveStoreFactory(new InMemoryArchiveShelf()),
            new FakeArchiveLockFactory(),
            new NullLogger(),
        );
        $this->lists = new ArchiveListServiceFactory(new InMemoryArchiveStoreFactory(new InMemoryArchiveShelf()), new NullLogger());
        $this->provider = new SwitchableOwnerProvider(new Owner('ada@example.com'));
    }

    public function test_builds_the_archive_service_for_the_owner_of_the_provider(): void
    {
        $ada = $this->resolverFor('ada@example.com');

        $first = $this->archiveService($ada)->archive(new UrlPointer(self::URL));
        $second = $this->archiveService($ada)->archive(new UrlPointer(self::URL));

        self::assertSame(ArchiveOutcome::Written, $first->outcome);
        self::assertSame(ArchiveOutcome::AlreadyStored, $second->outcome);
    }

    public function test_asks_the_provider_on_every_archive_call_and_keeps_no_service(): void
    {
        $resolver = $this->resolverFor('ada@example.com');

        $adaResult = $this->archiveService($resolver)->archive(new UrlPointer(self::URL));
        $this->provider->current = new Owner('bob@example.com');
        $bobResult = $this->archiveService($resolver)->archive(new UrlPointer(self::URL));

        self::assertSame(ArchiveOutcome::Written, $adaResult->outcome);
        self::assertSame(ArchiveOutcome::Written, $bobResult->outcome);
    }

    public function test_returns_the_list_service_of_the_current_owner(): void
    {
        $stores = new InMemoryArchiveStoreFactory(new InMemoryArchiveShelf());
        $ada = new Owner('ada@example.com');
        $stores->create($ada)->save(new Archive(
            self::URL,
            'Ada',
            null,
            null,
            '<p>A</p>',
            null,
            null,
        ));
        $resolver = $this->resolverFor('ada@example.com', new ArchiveListServiceFactory($stores, new NullLogger()));
        $query = ArchivePageQuery::from(null, null, null, null, 100);

        $adaPage = $this->listService($resolver)->page($query);
        $this->provider->current = new Owner('bea@example.com');
        $beaPage = $this->listService($resolver)->page($query);

        self::assertSame(1, $adaPage->total);
        self::assertSame(self::URL, $adaPage->items[0]->url);
        self::assertSame(0, $beaPage->total);
        self::assertSame([], $beaPage->items);
    }

    public function test_returns_one_service_for_each_known_type(): void
    {
        $resolver = $this->resolverFor('ada@example.com');

        $archives = $resolver->resolve(new Request(), $this->argument(ArchiveService::class));
        $lists = $resolver->resolve(new Request(), $this->argument(ArchiveListService::class));

        self::assertCount(1, $archives);
        self::assertInstanceOf(ArchiveService::class, $archives[0]);
        self::assertCount(1, $lists);
        self::assertInstanceOf(ArchiveListService::class, $lists[0]);
    }

    public function test_answers_a_nullable_argument_and_an_argument_with_a_default(): void
    {
        $resolver = $this->resolverFor('ada@example.com');

        foreach ([ArchiveService::class, ArchiveListService::class] as $type) {
            $nullable = new ArgumentMetadata('archives', $type, false, false, null, true);
            $withDefault = new ArgumentMetadata('archives', $type, false, true, null, true);

            self::assertCount(1, $resolver->resolve(new Request(), $nullable));
            self::assertCount(1, $resolver->resolve(new Request(), $withDefault));
        }
    }

    public function test_lets_an_owner_failure_escape_before_any_service_is_built(): void
    {
        $resolver = new OwnerScopedServiceResolver($this->ownerWhoFails(), $this->factory, $this->lists);

        foreach ([ArchiveService::class, ArchiveListService::class] as $type) {
            try {
                $resolver->resolve(new Request(), $this->argument($type));
                self::fail('The owner failure must escape.');
            } catch (\RuntimeException $failure) {
                self::assertSame('No owner.', $failure->getMessage());
            }
        }
    }

    public function test_ignores_an_argument_it_does_not_own_without_asking_the_owner(): void
    {
        $resolver = new OwnerScopedServiceResolver($this->ownerWhoFails(), $this->factory, $this->lists);

        self::assertSame([], $resolver->resolve(new Request(), $this->argument(CreateArchiveBody::class)));
        self::assertSame([], $resolver->resolve(new Request(), $this->argument(ListArchivesQuery::class)));
        self::assertSame([], $resolver->resolve(new Request(), $this->argument(null)));
    }

    private function resolverFor(string $email, ?ArchiveListServiceFactory $lists = null): OwnerScopedServiceResolver
    {
        $this->provider = new SwitchableOwnerProvider(new Owner($email));

        return new OwnerScopedServiceResolver($this->provider, $this->factory, $lists ?? $this->lists);
    }

    private function archiveService(OwnerScopedServiceResolver $resolver): ArchiveService
    {
        $resolved = $resolver->resolve(new Request(), $this->argument(ArchiveService::class));
        self::assertInstanceOf(ArchiveService::class, $resolved[0]);

        return $resolved[0];
    }

    private function listService(OwnerScopedServiceResolver $resolver): ArchiveListService
    {
        $resolved = $resolver->resolve(new Request(), $this->argument(ArchiveListService::class));
        self::assertInstanceOf(ArchiveListService::class, $resolved[0]);

        return $resolved[0];
    }

    private function argument(?string $type): ArgumentMetadata
    {
        return new ArgumentMetadata('argument', $type, false, false, null);
    }

    private function ownerWhoFails(): OwnerProvider
    {
        return new class () implements OwnerProvider {
            public function owner(): Owner
            {
                throw new \RuntimeException('No owner.');
            }
        };
    }
}
