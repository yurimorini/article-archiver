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
use Yumo\Eleanor\Archive\InMemory\InMemoryArchiveShelf;
use Yumo\Eleanor\Archive\InMemory\InMemoryArchiveStoreFactory;
use Yumo\Eleanor\Archive\Owner;
use Yumo\Eleanor\Tests\Support\FakeArticleFetcher;
use Yumo\Eleanor\Tests\Support\PipelineFixtures;

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
        $this->factory = new ArchiveServiceFactory($fetcher, new InMemoryArchiveStoreFactory(new InMemoryArchiveShelf()));
    }

    public function test_builds_the_service_for_the_owner_of_the_provider(): void
    {
        $ada = $this->resolverFor('ada@example.com');
        $bob = $this->resolverFor('bob@example.com');

        $first = $this->resolveService($ada)->archive(self::URL);
        $second = $this->resolveService($ada)->archive(self::URL);
        $other = $this->resolveService($bob)->archive(self::URL);

        self::assertSame(ArchiveOutcome::Written, $first->outcome);
        self::assertSame(ArchiveOutcome::AlreadyStored, $second->outcome);
        self::assertSame(ArchiveOutcome::Written, $other->outcome);
    }

    public function test_returns_a_single_archive_service(): void
    {
        $resolved = $this->resolverFor('ada@example.com')->resolve(new Request(), $this->argument(ArchiveService::class));

        self::assertCount(1, $resolved);
        self::assertInstanceOf(ArchiveService::class, $resolved[0]);
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
        $owner = new Owner($email);
        $provider = new class($owner) implements OwnerProvider {
            public function __construct(private readonly Owner $owner)
            {
            }

            public function owner(): Owner
            {
                return $this->owner;
            }
        };

        return new ArchiveServiceResolver($provider, $this->factory);
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
