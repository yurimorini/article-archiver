<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Import;

/**
 * The rows of one import file, one candidate at a time.
 *
 * A blank row is null. Any other row is the trimmed string.
 */
interface CandidateStream
{
    /**
     * Yields each candidate in file order.
     *
     * @return \Generator<int, string|null>
     */
    public function candidates(): \Generator;
}
