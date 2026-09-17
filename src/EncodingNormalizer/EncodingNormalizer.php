<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

use Ddeboer\Transcoder\Exception\UnsupportedEncodingException;
use Fossar\GuzzleTranscoder\ContentTypeExtractor;
use Yumo\LogRead\HttpFetcher\FetchedPage;

/**
 * This class turns opaque HTML response bytes into a UTF-8 HTML document, or throws.
 *
 * Encoding is taken from a BOM, the HTTP Content-Type charset, a short HTML meta
 * prescan, a UTF-8 validity check, then an optional `mb_detect_encoding` guess.
 * An HTTP charset wins over a conflicting meta declaration. The result is tagged
 * Ok or Degraded so callers can log a warning without treating lossy conversion
 * as a hard failure.
 *
 * `$outcome = (new EncodingNormalizer())->normalize($page);`
 *
 * Unit tests may inject a `Utf8Converter` to force conversion failures.
 */
final class EncodingNormalizer
{
    /** This constant holds how many leading body bytes are scanned for `<meta charset>` / `http-equiv`. */
    private const META_PRESCAN_BYTES = 1024;

    /**
     * This constant holds the candidate encodings for `mb_detect_encoding` when nothing was declared.
     *
     * @var list<string>
     */
    private const DETECT_CANDIDATES = ['UTF-8', 'Windows-1252', 'ISO-8859-1'];

    /**
     * This constant maps leading BOM byte sequences to encoding names.
     *
     * Longer signatures come first so UTF-32LE (`FF FE 00 00`) is not matched as UTF-16LE (`FF FE`).
     *
     * @var array<string, string>
     */
    private const BOM_ENCODINGS = [
        "\x00\x00\xFE\xFF" => 'UTF-32BE',
        "\xFF\xFE\x00\x00" => 'UTF-32LE',
        "\xEF\xBB\xBF" => 'UTF-8',
        "\xFE\xFF" => 'UTF-16BE',
        "\xFF\xFE" => 'UTF-16LE',
    ];

    /** This value holds the converter that turns named encodings into UTF-8, including a lossy fallback. */
    private Utf8Converter $converter;

    /**
     * This constructor creates the normalizer. When `$converter` is omitted, the production transcoder adapter is used.
     */
    public function __construct(?Utf8Converter $converter = null)
    {
        $this->converter = $converter ?? new TranscoderUtf8Converter();
    }

    /**
     * This method converts `$page->body` to UTF-8 HTML and copies `$page->requestUri` onto the result.
     *
     * @throws EncodingNormalizerException When even lossy UTF-8 cannot be produced
     */
    public function normalize(FetchedPage $page): EncodingOutcome
    {
        $bytes = $page->body;
        $detected = $this->detect($bytes, $page->contentType);

        $working = $bytes;
        if ($detected->source === EncodingSource::Bom && $this->isUtf8Name($detected->encoding)) {
            $working = $this->stripUtf8Bom($working);
        }

        $warning = $detected->warning;
        $previous = null;

        if (!$detected->isWeak()) {
            try {
                $html = $this->convertStrict($working, $detected->encoding);
                $html = $this->stripUtf8Bom($html);
                if (!mb_check_encoding($html, 'UTF-8')) {
                    throw new EncodingNormalizerException(EncodingError::Conversion);
                }

                return EncodingOutcome::ok(new Utf8Html(
                    $html,
                    $page->requestUri,
                    $detected->encoding,
                    $detected->source,
                ));
            } catch (EncodingNormalizerException $e) {
                $warning = $e->error;
                $previous = $e->getPrevious();
            } catch (UnsupportedEncodingException $e) {
                $warning = EncodingError::Unsupported;
                $previous = $e;
            } catch (\Throwable $e) {
                $warning = EncodingError::Conversion;
                $previous = $e;
            }
        }

        return $this->degradedFromLossy(
            $working,
            $page->requestUri,
            $detected->encoding,
            $detected->source,
            $warning ?? EncodingError::Conversion,
            $previous,
        );
    }

    /**
     * This method chooses the encoding of `$bytes` from a BOM, HTTP charset, HTML meta, UTF-8 validity, or an mbstring guess.
     */
    private function detect(string $bytes, string $contentType): DetectedEncoding
    {
        $bomEncoding = $this->detectBom($bytes);
        if ($bomEncoding !== null) {
            if ($this->isUtf8Name($bomEncoding)) {
                return DetectedEncoding::trusted($bomEncoding, EncodingSource::Bom);
            }

            return DetectedEncoding::weak($bomEncoding, EncodingSource::Bom, EncodingError::Unsupported);
        }

        $httpCharset = $this->parseHttpCharset($contentType);
        if ($httpCharset !== null) {
            return DetectedEncoding::trusted($httpCharset, EncodingSource::HttpHeader);
        }

        $metaCharset = $this->parseMetaCharset($bytes);
        if ($metaCharset !== null) {
            return DetectedEncoding::trusted($metaCharset, EncodingSource::Meta);
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return DetectedEncoding::trusted('UTF-8', EncodingSource::Utf8Default);
        }

        $guessed = mb_detect_encoding($bytes, self::DETECT_CANDIDATES, true);
        if (is_string($guessed) && $guessed !== '') {
            return DetectedEncoding::weak($guessed, EncodingSource::Detect, EncodingError::Undeclared);
        }

        return DetectedEncoding::weak('UTF-8', EncodingSource::Lossy, EncodingError::Undeclared);
    }

    private function detectBom(string $bytes): ?string
    {
        foreach (self::BOM_ENCODINGS as $bomBytes => $encoding) {
            if (str_starts_with($bytes, $bomBytes)) {
                return $encoding;
            }
        }

        return null;
    }

    private function parseHttpCharset(string $contentType): ?string
    {
        if ($contentType === '') {
            return null;
        }

        $extracted = ContentTypeExtractor::getContentTypeFromHeader(
            ['Content-Type' => $contentType],
            'utf-8',
        );
        if ($extracted === null) {
            return null;
        }

        $charset = $extracted[1];
        if ($charset === null || trim($charset) === '') {
            return null;
        }

        return $charset;
    }

    private function parseMetaCharset(string $bytes): ?string
    {
        $prescan = substr($bytes, 0, self::META_PRESCAN_BYTES);
        [$charset] = ContentTypeExtractor::getContentTypeFromHtml($prescan, 'utf-8');
        if ($charset === null || trim($charset) === '') {
            return null;
        }

        return $charset;
    }

    private function convertStrict(string $bytes, string $fromEncoding): string
    {
        if ($this->isUtf8Name($fromEncoding)) {
            if (!mb_check_encoding($bytes, 'UTF-8')) {
                throw new EncodingNormalizerException(EncodingError::Conversion);
            }

            return $bytes;
        }

        if ($this->isSupportedMbEncoding($fromEncoding) && !mb_check_encoding($bytes, $fromEncoding)) {
            throw new EncodingNormalizerException(EncodingError::Conversion);
        }

        return $this->converter->convert($bytes, $fromEncoding);
    }

    private function degradedFromLossy(
        string $bytes,
        string $sourceUrl,
        string $sourceEncoding,
        EncodingSource $source,
        EncodingError $warning,
        ?\Throwable $previous,
    ): EncodingOutcome {
        try {
            $html = $this->converter->convertLossy($bytes, $sourceEncoding);
            $html = $this->stripUtf8Bom($html);
            if (!mb_check_encoding($html, 'UTF-8')) {
                throw new EncodingNormalizerException($warning, '', $previous);
            }

            return EncodingOutcome::degraded(
                new Utf8Html($html, $sourceUrl, $sourceEncoding, $source),
                $warning,
                $previous,
            );
        } catch (EncodingNormalizerException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EncodingNormalizerException($warning, '', $e);
        }
    }

    private function stripUtf8Bom(string $html): string
    {
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            return substr($html, 3);
        }

        return $html;
    }

    private function isUtf8Name(string $encoding): bool
    {
        return strtoupper(str_replace(['-', '_'], '', $encoding)) === 'UTF8';
    }

    private function isSupportedMbEncoding(string $encoding): bool
    {
        foreach (mb_list_encodings() as $name) {
            if (strcasecmp($name, $encoding) === 0) {
                return true;
            }
        }

        return false;
    }
}
