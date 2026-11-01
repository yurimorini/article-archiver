<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Probe;

use Yumo\Eleanor\Probe\Io;
use Yumo\Eleanor\Probe\Job;
use Yumo\Eleanor\Probe\Settled;

/**
 * Scripts job outcomes and records when each job was started.
 */
final class FakeIo implements Io
{
    /** @var list<array{job: Job, polls: int}> */
    public array $starts = [];

    public int $stops = 0;

    /** @var array<string, Settled> */
    private array $scripted = [];

    /** @var array<string, true> */
    private array $held = [];

    /** @var list<Job> */
    private array $pending = [];

    private int $polls = 0;

    /** @var (\Closure(Job): void)|null */
    private ?\Closure $onStart = null;

    /**
     * Supplies the outcome `poll` returns for a job named `$name`.
     */
    public function script(string $name, Settled $settled): void
    {
        $this->scripted[$name] = $settled;
    }

    /**
     * Keeps the job named `$name` in flight so `poll` does not return it.
     */
    public function hold(string $name): void
    {
        $this->held[$name] = true;
    }

    /**
     * Runs `$onStart` inside `start`, after the start has been recorded.
     *
     * @param \Closure(Job): void $onStart
     */
    public function onStart(\Closure $onStart): void
    {
        $this->onStart = $onStart;
    }

    public function start(Job $job): void
    {
        $this->starts[] = [
            'job' => $job,
            'polls' => $this->polls,
        ];
        $this->pending[] = $job;

        $onStart = $this->onStart;
        if ($onStart !== null) {
            $onStart($job);
        }
    }

    public function poll(): array
    {
        ++$this->polls;

        $settled = [];
        $stillPending = [];
        foreach ($this->pending as $job) {
            if (isset($this->held[$job->name]) || !isset($this->scripted[$job->name])) {
                $stillPending[] = $job;
                continue;
            }

            $settled[] = $this->scripted[$job->name];
        }

        $this->pending = $stillPending;

        return $settled;
    }

    public function stop(): void
    {
        ++$this->stops;
    }
}
