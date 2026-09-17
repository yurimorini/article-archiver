<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * This class holds the encoding name chosen from a BOM, HTTP charset, HTML meta, UTF-8 validity, or a guess.
 *
 * A trusted instance has no warning and may be converted strictly. A weak instance always carries
 * an `EncodingError` so the caller can skip strict conversion and repair the bytes.
 *
 * `$detected = DetectedEncoding::trusted('UTF-8', EncodingSource::HttpHeader);`
 */
final readonly class DetectedEncoding
{
    private function __construct(
        /** This value holds the encoding name that was declared, assumed, or guessed. */
        public string $encoding,
        /** This value records where `$encoding` was taken from. */
        public EncodingSource $source,
        /** This value holds the reason the detection is weak, and it is always null when the detection is trusted. */
        public ?EncodingError $warning,
    ) {
        if (trim($encoding) === '') {
            throw new \InvalidArgumentException('encoding must not be empty');
        }
    }

    /**
     * This method builds a detection that came from a trusted signal and has no warning.
     */
    public static function trusted(string $encoding, EncodingSource $source): self
    {
        return new self($encoding, $source, null);
    }

    /**
     * This method builds a detection that is not trusted enough for strict conversion, and the warning argument is required.
     */
    public static function weak(string $encoding, EncodingSource $source, EncodingError $warning): self
    {
        return new self($encoding, $source, $warning);
    }

    /**
     * This method returns whether the detection is too weak for strict conversion.
     */
    public function isWeak(): bool
    {
        return $this->warning !== null;
    }
}
