<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

/**
 * Starts jobs, reports the ones that have finished, and stops the server.
 */
interface Io
{
    /**
     * Starts `$job`. It stays in flight until `poll` returns it.
     */
    public function start(Job $job): void;

    /**
     * Returns the jobs that have settled since the previous poll.
     *
     * @return list<Settled>
     */
    public function poll(): array;

    /**
     * Stops the server.
     */
    public function stop(): void;
}
