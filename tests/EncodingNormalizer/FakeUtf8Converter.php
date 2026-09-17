<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\EncodingNormalizer;

use Yumo\LogRead\EncodingNormalizer\Utf8Converter;

/**
 * This class fails strict conversion and either fails or returns a fixed lossy result.
 *
 * This test double exercises the hard-failure path without depending on mbstring because
 * lossy `mb_scrub` almost always yields valid UTF-8.
 */
final class FakeUtf8Converter implements Utf8Converter
{
    public function __construct(
        /** This value holds the exception thrown from every `convert()` call. */
        private readonly \Throwable $convertError,
        /** This value holds the exception thrown from `convertLossy()` or the lossy HTML returned by it. */
        private readonly \Throwable|string $lossy,
    ) {
    }

    /**
     * This method always throws the configured strict-conversion exception.
     */
    public function convert(string $bytes, string $fromEncoding): string
    {
        throw $this->convertError;
    }

    /**
     * This method throws the configured lossy exception or returns the configured lossy HTML.
     */
    public function convertLossy(string $bytes, string $fromEncoding): string
    {
        if ($this->lossy instanceof \Throwable) {
            throw $this->lossy;
        }

        return $this->lossy;
    }
}
