<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

/**
 * Picks the configured list or one named probe step.
 */
final class Selection
{
    /**
     * Returns `$configured` when `$argv` is empty, or the one named step.
     *
     * @param list<string> $configured
     * @param list<string> $argv
     *
     * @return list<string>
     */
    public static function names(array $configured, array $argv): array
    {
        if ($argv === []) {
            return $configured;
        }

        if (count($argv) !== 1) {
            throw new \InvalidArgumentException('Name at most one probe step.');
        }

        $name = $argv[0];
        $known = [
            ...Catalog::writeChain(),
            ...Catalog::reads(),
            ...Catalog::refusals(),
        ];

        if (!in_array($name, $known, true)) {
            throw new \InvalidArgumentException('Unknown probe step: ' . $name);
        }

        return [$name];
    }
}
