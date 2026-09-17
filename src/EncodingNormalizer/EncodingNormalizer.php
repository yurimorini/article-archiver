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
        if ($detected['source'] === EncodingSource::Bom && $this->isUtf8Name($detected['encoding'])) {
            $working = $this->stripUtf8Bom($working);
        }

        $warning = $detected['warning'];
        $previous = null;

        if (!$detected['weak']) {
            try {
                $html = $this->convertStrict($working, $detected['encoding']);
                $html = $this->stripUtf8Bom($html);
                if (!mb_check_encoding($html, 'UTF-8')) {
                    throw new EncodingNormalizerException(EncodingError::Conversion);
                }

                return EncodingOutcome::ok(new Utf8Html(
                    $html,
                    $page->requestUri,
                    $detected['encoding'],
                    $detected['source'],
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
            $detected['encoding'],
            $detected['source'],
            $warning ?? EncodingError::Conversion,
            $previous,
        );
    }

    /**
     * This method chooses the encoding of `$bytes` from a BOM, HTTP charset, HTML meta, UTF-8 validity, or an mbstring guess.
     *
     * @return array{encoding: string, source: EncodingSource, weak: bool, warning: ?EncodingError}
     */
    private function detect(string $bytes, string $contentType): array
    {
        $bom = $this->detectBom($bytes);
        if ($bom !== null) {
            $weak = !$this->isUtf8Name($bom['encoding']);

            return [
                'encoding' => $bom['encoding'],
                'source' => EncodingSource::Bom,
                'weak' => $weak,
                'warning' => $weak ? EncodingError::Unsupported : null,
            ];
        }

        $httpCharset = $this->parseHttpCharset($contentType);
        if ($httpCharset !== null) {
            return [
                'encoding' => $httpCharset,
                'source' => EncodingSource::HttpHeader,
                'weak' => false,
                'warning' => null,
            ];
        }

        $metaCharset = $this->parseMetaCharset($bytes);
        if ($metaCharset !== null) {
            return [
                'encoding' => $metaCharset,
                'source' => EncodingSource::Meta,
                'weak' => false,
                'warning' => null,
            ];
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return [
                'encoding' => 'UTF-8',
                'source' => EncodingSource::Utf8Default,
                'weak' => false,
                'warning' => null,
            ];
        }

        $guessed = mb_detect_encoding($bytes, self::DETECT_CANDIDATES, true);
        if (is_string($guessed) && $guessed !== '') {
            return [
                'encoding' => $guessed,
                'source' => EncodingSource::Detect,
                'weak' => true,
                'warning' => EncodingError::Undeclared,
            ];
        }

        return [
            'encoding' => 'UTF-8',
            'source' => EncodingSource::Lossy,
            'weak' => true,
            'warning' => EncodingError::Undeclared,
        ];
    }

    /**
     * @return array{encoding: string}|null
     */
    private function detectBom(string $bytes): ?array
    {
        if (str_starts_with($bytes, "\x00\x00\xFE\xFF")) {
            return ['encoding' => 'UTF-32BE'];
        }
        if (str_starts_with($bytes, "\xFF\xFE\x00\x00")) {
            return ['encoding' => 'UTF-32LE'];
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return ['encoding' => 'UTF-8'];
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return ['encoding' => 'UTF-16BE'];
        }
        if (str_starts_with($bytes, "\xFF\xFE")) {
            return ['encoding' => 'UTF-16LE'];
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
