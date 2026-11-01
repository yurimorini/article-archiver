<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Probe;

/**
 * Reads the probe document and fills the defaults.
 */
final readonly class Config
{
    /**
     * Stores the corpus path, the sample size, the optional owner, the port, the optional seed, and the steps.
     *
     * @param list<string> $steps
     */
    public function __construct(
        public string $urls,
        public int $sample,
        public ?string $owner,
        public int $port,
        public ?int $seed,
        public array $steps,
    ) {
    }

    /**
     * Reads `$path` and fills a missing sample, port, seed, or owner.
     *
     * A missing sample is 10. A missing port is 8000. A missing seed is null. A missing owner is null.
     * A sample outside 1..10, or a document with no steps, is a broken probe.
     */
    public static function fromFile(string $path): self
    {
        $decoded = self::decode($path);

        return new self(
            self::urls($decoded),
            self::sample($decoded),
            self::owner($decoded),
            self::port($decoded),
            self::seed($decoded),
            self::steps($decoded),
        );
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException('The probe config cannot be read.');
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('The probe config is not JSON.', 0, $error);
        }

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('The probe config must be an object.');
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $decoded
     */
    private static function urls(array $decoded): string
    {
        $urls = $decoded['urls'] ?? 'var/log-urls.txt';
        if (!is_string($urls) || $urls === '') {
            throw new \InvalidArgumentException('The probe urls must be a path.');
        }

        return $urls;
    }

    /**
     * @param array<mixed> $decoded
     */
    private static function sample(array $decoded): int
    {
        $sample = $decoded['sample'] ?? 10;
        if (!is_int($sample) || $sample < 1 || $sample > 10) {
            throw new \InvalidArgumentException('Sample must be between 1 and 10.');
        }

        return $sample;
    }

    /**
     * @param array<mixed> $decoded
     */
    private static function owner(array $decoded): ?string
    {
        if (!array_key_exists('owner', $decoded) || $decoded['owner'] === null) {
            return null;
        }

        $owner = $decoded['owner'];
        if (!is_string($owner)) {
            throw new \InvalidArgumentException('The probe owner must be a string.');
        }

        return $owner;
    }

    /**
     * @param array<mixed> $decoded
     */
    private static function port(array $decoded): int
    {
        $port = $decoded['port'] ?? 8000;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('The probe port must be a TCP port.');
        }

        return $port;
    }

    /**
     * @param array<mixed> $decoded
     */
    private static function seed(array $decoded): ?int
    {
        if (!array_key_exists('seed', $decoded) || $decoded['seed'] === null) {
            return null;
        }

        $seed = $decoded['seed'];
        if (!is_int($seed)) {
            throw new \InvalidArgumentException('The probe seed must be an integer.');
        }

        return $seed;
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return list<string>
     */
    private static function steps(array $decoded): array
    {
        if (!array_key_exists('steps', $decoded)) {
            throw new \InvalidArgumentException('The probe steps are missing.');
        }

        $steps = $decoded['steps'];
        if (!is_array($steps) || !array_is_list($steps)) {
            throw new \InvalidArgumentException('The probe steps must be a list.');
        }

        $known = [
            ...Catalog::writeChain(),
            ...Catalog::reads(),
            ...Catalog::refusals(),
        ];
        $names = [];
        foreach ($steps as $step) {
            if (!is_string($step) || !in_array($step, $known, true)) {
                throw new \InvalidArgumentException('Unknown probe step.');
            }

            $names[] = $step;
        }

        return $names;
    }
}
