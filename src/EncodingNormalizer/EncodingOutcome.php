<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

/**
 * Result of turning fetched HTML bytes into UTF-8.
 *
 * Every outcome includes `Utf8Html`. `quality` says whether conversion was clean (`Ok`)
 * or best-effort (`Degraded`). A degraded outcome always carries an `EncodingError` warning
 * so a caller can log it and continue.
 *
 * `$outcome = EncodingOutcome::ok($html);`
 */
final readonly class EncodingOutcome
{
    private function __construct(
        /** Whether conversion was clean or best-effort. */
        public EncodingQuality $quality,
        /** UTF-8 HTML produced for this page. */
        public Utf8Html $html,
        /** Why the result is degraded; always `null` on `Ok`. */
        public ?EncodingError $warning = null,
        /** Underlying converter exception when conversion was repaired or is being explained. */
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
     * Builds a clean conversion result with no warning.
     */
    public static function ok(Utf8Html $html): self
    {
        return new self(EncodingQuality::Ok, $html);
    }

    /**
     * Builds a best-effort conversion result. `$warning` is required.
     */
    public static function degraded(
        Utf8Html $html,
        EncodingError $warning,
        ?\Throwable $previous = null,
    ): self {
        return new self(EncodingQuality::Degraded, $html, $warning, $previous);
    }

    /**
     * Returns whether conversion was clean.
     */
    public function isOk(): bool
    {
        return $this->quality === EncodingQuality::Ok;
    }

    /**
     * Returns whether conversion was best-effort.
     */
    public function isDegraded(): bool
    {
        return $this->quality === EncodingQuality::Degraded;
    }
}
