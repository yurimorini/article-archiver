<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Owner;

use Yumo\Eleanor\Archive\Owner;

/**
 * Tells which owner the current call acts for.
 */
interface OwnerProvider
{
    /**
     * Returns the owner of the current call.
     */
    public function owner(): Owner;
}
