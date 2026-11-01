<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

/**
 * Picks a seeded sample of lines for the Eleanor probe.
 */
final class Draw
{
    /**
     * Returns at most `$sample` non-blank lines after a seeded shuffle.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public static function take(array $lines, int $seed, int $sample): array
    {
        if ($sample < 1 || $sample > 10) {
            throw new \InvalidArgumentException('Sample must be between 1 and 10.');
        }

        $kept = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $kept[] = $trimmed;
            }
        }

        mt_srand($seed);
        for ($i = count($kept) - 1; $i > 0; --$i) {
            $j = mt_rand(0, $i);
            $swap = $kept[$i];
            $kept[$i] = $kept[$j];
            $kept[$j] = $swap;
        }

        return array_slice($kept, 0, $sample);
    }
}
