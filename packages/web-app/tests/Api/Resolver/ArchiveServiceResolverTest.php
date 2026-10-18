<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Api\Resolver;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Yumo\Eleanor\Api\Dto\CreateArchiveBody;
use Yumo\Eleanor\Api\Owner\OwnerProvider;
use Yumo\Eleanor\Api\Resolver\ArchiveServiceResolver;
use Yumo\Eleanor\Archive\ArchiveOutcome;
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
 * Proves that the resolver hands the owner's archive service to an action argument and ignores every other argument.
 */
final class ArchiveServiceResolverTest extends TestCase
{
    private const string URL = 'https://example.com/a';

    private ArchiveServiceFactory $factory;

    protected function setUp(): void
    {
        $fetcher = new FakeArticleFetcher();
        $fetcher->willReturn(self::URL, PipelineFixtures::success());
        $this->factory = new ArchiveServiceFactory(
            $fetcher,
            new InMemoryArchiveStoreFactory(new InMemoryArchiveShelf()),
            new FakeArchiveLockFactory(),
        );
    }

    public function test_builds_the_service_for_the_owner_of_the_provider(): void
    {
        $ada = $this->resolverFor('ada@example.com');

        $first = $this->resolveService($ada)->archive(new UrlPointer(self::URL));
        $second = $this->resolveService($ada)->archive(new UrlPointer(self::URL));

        self::assertSame(ArchiveOutcome::Written, $first->outcome);
        self::assertSame(ArchiveOutcome::AlreadyStored, $second->outcome);
    }

    public function test_asks_the_provider_on_every_call_and_keeps_no_service(): void
    {
        $provider = new SwitchableOwnerProvider(new Owner('ada@example.com'));
        $resolver = new ArchiveServiceResolver($provider, $this->factory);

        $adaResult = $this->resolveService($resolver)->archive(new UrlPointer(self::URL));
        $provider->current = new Owner('bob@example.com');
        $bobResult = $this->resolveService($resolver)->archive(new UrlPointer(self::URL));

        self::assertSame(ArchiveOutcome::Written, $adaResult->outcome);
        self::assertSame(ArchiveOutcome::Written, $bobResult->outcome);
    }

    public function test_lets_an_owner_failure_escape_before_any_service_is_built(): void
    {
        $provider = new class () implements OwnerProvider {
            public function owner(): Owner
            {
                throw new \RuntimeException('No owner.');
            }
        };
        $resolver = new ArchiveServiceResolver($provider, $this->factory);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No owner.');

        $resolver->resolve(new Request(), $this->argument(ArchiveService::class));
    }

    public function test_returns_a_single_archive_service(): void
    {
        $resolved = $this->resolverFor('ada@example.com')->resolve(new Request(), $this->argument(ArchiveService::class));

        self::assertCount(1, $resolved);
        self::assertInstanceOf(ArchiveService::class, $resolved[0]);
    }

    public function test_answers_a_nullable_argument_and_an_argument_with_a_default(): void
    {
        $resolver = $this->resolverFor('ada@example.com');
        $nullable = new ArgumentMetadata('archives', ArchiveService::class, false, false, null, true);
        $withDefault = new ArgumentMetadata('archives', ArchiveService::class, false, true, null, true);

        self::assertCount(1, $resolver->resolve(new Request(), $nullable));
        self::assertCount(1, $resolver->resolve(new Request(), $withDefault));
    }

    public function test_ignores_an_argument_of_another_type(): void
    {
        $resolved = $this->resolverFor('ada@example.com')->resolve(new Request(), $this->argument(CreateArchiveBody::class));

        self::assertSame([], $resolved);
    }

    public function test_ignores_an_argument_with_no_type(): void
    {
        $resolved = $this->resolverFor('ada@example.com')->resolve(new Request(), $this->argument(null));

        self::assertSame([], $resolved);
    }

    private function resolverFor(string $email): ArchiveServiceResolver
    {
        return new ArchiveServiceResolver(new SwitchableOwnerProvider(new Owner($email)), $this->factory);
    }

    private function resolveService(ArchiveServiceResolver $resolver): ArchiveService
    {
        $resolved = $resolver->resolve(new Request(), $this->argument(ArchiveService::class));
        self::assertInstanceOf(ArchiveService::class, $resolved[0]);

        return $resolved[0];
    }

    private function argument(?string $type): ArgumentMetadata
    {
        return new ArgumentMetadata('argument', $type, false, false, null);
    }
}
