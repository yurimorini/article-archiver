<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

/**
 * Makes the store that holds one owner's archives.
 */
interface ArchiveStoreFactory
{
    /**
     * Returns the store for `$owner`.
     */
    public function create(Owner $owner): ArchiveStore;
}
