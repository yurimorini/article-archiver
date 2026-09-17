<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * This class represents the result of turning fetched HTML bytes into UTF-8.
 *
 * Every instance includes `Utf8Html`, and `EncodingQuality` states whether conversion was clean or best-effort.
 * A degraded instance always carries an `EncodingError` warning so a caller can log it and continue.
 *
 * `$outcome = EncodingOutcome::ok($html);`
 */
final readonly class EncodingOutcome
{
    private function __construct(
        /** This value indicates whether conversion was clean or best-effort. */
        public EncodingQuality $quality,
        /** This value holds the UTF-8 HTML produced for this page. */
        public Utf8Html $html,
        /** This value holds the reason the result is degraded, and it is always null when quality is Ok. */
        public ?EncodingError $warning = null,
        /** This value holds the underlying converter exception when conversion was repaired or is being explained. */
        public ?\Throwable $previous = null,
    ) {
        if ($quality === EncodingQuality::Ok && $warning !== null) {
            throw new \InvalidArgumentException('Ok outcome must not carry a warning');
        }
        if ($quality === EncodingQuality::Degraded && $warning === null) {
            throw new \InvalidArgumentException('Degraded outcome requires a warning');
        }
    }

    /**
     * This method builds a result for a clean conversion with no warning.
     */
    public static function ok(Utf8Html $html): self
    {
        return new self(EncodingQuality::Ok, $html);
    }

    /**
     * This method builds a result for a best-effort conversion, and the warning argument is required.
     */
    public static function degraded(
        Utf8Html $html,
        EncodingError $warning,
        ?\Throwable $previous = null,
    ): self {
        return new self(EncodingQuality::Degraded, $html, $warning, $previous);
    }

    /**
     * This method returns whether conversion was clean.
     */
    public function isOk(): bool
    {
        return $this->quality === EncodingQuality::Ok;
    }

    /**
     * This method returns whether conversion was best-effort.
     */
    public function isDegraded(): bool
    {
        return $this->quality === EncodingQuality::Degraded;
    }
}
