<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Support;

use Yumo\Eleanor\Api\Owner\OwnerProvider;
use Yumo\Eleanor\Archive\Owner;

/**
 * Answers with the owner a test sets, and lets the test change it between calls.
 */
final class SwitchableOwnerProvider implements OwnerProvider
{
    /**
     * Creates the provider with its first owner.
     */
    public function __construct(
        /** The owner the next call answers with. */
        public Owner $current,
    ) {
    }

    public function owner(): Owner
    {
        return $this->current;
    }
}
