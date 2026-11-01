<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Yumo\Eleanor\Api\Owner\OwnerProvider;
use Yumo\Eleanor\Archive\ArchiveReadService;
use Yumo\Eleanor\Archive\ArchiveReadServiceFactory;
use Yumo\Eleanor\Archive\ArchiveService;
use Yumo\Eleanor\Archive\ArchiveServiceFactory;

/**
 * Builds the current owner's service for an action argument of type `ArchiveService` or `ArchiveReadService`.
 *
 * A controller declares the service and does not build it.
 */
final class OwnerScopedServiceResolver implements ValueResolverInterface
{
    /**
     * Creates the resolver from the current owner and the factories of the owner-scoped services.
     */
    public function __construct(
        /** The source of the owner of the current call. */
        private OwnerProvider $owners,
        /** Builds the archive service of one owner. */
        private ArchiveServiceFactory $archives,
        /** Builds the read service of one owner. */
        private ArchiveReadServiceFactory $lists,
    ) {
    }

    /**
     * Returns the service of the current owner when the argument type is one it knows, and nothing otherwise.
     *
     * `owner()` runs only for a matching type.
     *
     * @return list<ArchiveService|ArchiveReadService>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        return match ($argument->getType()) {
            ArchiveService::class => [$this->archives->create($this->owners->owner())],
            ArchiveReadService::class => [$this->lists->create($this->owners->owner())],
            default => [],
        };
    }
}
