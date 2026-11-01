<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Yumo\Eleanor\Api\Owner\OwnerProvider;
use Yumo\Eleanor\Archive\ArchiveListService;
use Yumo\Eleanor\Archive\ArchiveListServiceFactory;

/**
 * Builds the list service of the current owner for a controller argument of type `ArchiveListService`.
 *
 * A controller declares `ArchiveListService $archives` and does not build the service itself.
 */
final class ArchiveListServiceResolver implements ValueResolverInterface
{
    /**
     * Creates the resolver from the source of the current owner and the factory of per-owner list services.
     */
    public function __construct(
        /** The source of the owner of the current call. */
        private OwnerProvider $owners,
        /** The factory that builds the list service of one owner. */
        private ArchiveListServiceFactory $archives,
    ) {
    }

    /**
     * Returns the list service of the current owner for an `ArchiveListService` argument, and nothing for any other argument.
     *
     * @return list<ArchiveListService>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        if ($argument->getType() !== ArchiveListService::class) {
            return [];
        }

        return [$this->archives->create($this->owners->owner())];
    }
}
