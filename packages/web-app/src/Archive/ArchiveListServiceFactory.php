<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

/**
 * Builds the list service of one owner. It keeps no state.
 *
 * `$service = $factory->create(new Owner('ada@example.com'));`
 */
#[WithMonologChannel('domain')]
final class ArchiveListServiceFactory
{
    /**
     * Creates the factory from the factory of per-owner stores and the domain logger.
     */
    public function __construct(
        /** The factory that makes each owner's store. */
        private ArchiveStoreFactory $stores,
        /** The domain logger passed to every service this factory builds. */
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Returns a service that reads the archives of `$owner`.
     */
    public function create(Owner $owner): ArchiveListService
    {
        return new ArchiveListService($this->stores->create($owner), $this->logger);
    }
}
