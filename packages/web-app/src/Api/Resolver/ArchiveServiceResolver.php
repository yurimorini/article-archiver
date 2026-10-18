<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Yumo\Eleanor\Api\Owner\OwnerProvider;
use Yumo\Eleanor\Archive\ArchiveService;
use Yumo\Eleanor\Archive\ArchiveServiceFactory;

/**
 * Builds the archive service of the current owner for a controller argument of type `ArchiveService`.
 *
 * A controller declares `ArchiveService $archives` and does not build the service itself.
 */
final class ArchiveServiceResolver implements ValueResolverInterface
{
    /**
     * Creates the resolver from the source of the current owner and the factory of per-owner services.
     */
    public function __construct(
        /** The source of the owner of the current call. */
        private OwnerProvider $owners,
        /** The factory that builds the service of one owner. */
        private ArchiveServiceFactory $archives,
    ) {
    }

    /**
     * Returns the service of the current owner for an `ArchiveService` argument, and nothing for any other argument.
     *
     * @return list<ArchiveService>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        if ($argument->getType() !== ArchiveService::class) {
            return [];
        }

        return [$this->archives->create($this->owners->owner())];
    }
}
