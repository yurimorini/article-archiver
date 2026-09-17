<?php

declare(strict_types=1);

namespace Yumo\LogRead\EncodingNormalizer;

use Ddeboer\Transcoder\Exception\UnsupportedEncodingException;
use Fossar\GuzzleTranscoder\ContentTypeExtractor;
use Yumo\LogRead\HttpFetcher\FetchedPage;

/**
 * This class turns opaque HTML response bytes into a UTF-8 HTML document, or throws.
 *
 * Encoding is taken from a UTF-8 BOM, the HTTP Content-Type charset, a short HTML meta
 * prescan, or a UTF-8 validity check, in that order. An HTTP charset wins over a
 * conflicting meta declaration. The result is tagged Ok or Degraded so callers can log
 * a warning without treating lossy conversion as a hard failure.
 *
 * `$outcome = (new EncodingNormalizer())->normalize($page);`
 *
 * Unit tests may inject a `Utf8Converter` to force conversion failures.
 */
final class EncodingNormalizer
{
    /** This constant holds how many leading body bytes are scanned for `<meta charset>` / `http-equiv`. */
    private const META_PRESCAN_BYTES = 1024;

    /** This value holds the converter that turns named encodings into UTF-8. */
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
            throw $e;
        } catch (UnsupportedEncodingException $e) {
            throw new EncodingNormalizerException(EncodingError::Unsupported, '', $e);
        } catch (\Throwable $e) {
            throw new EncodingNormalizerException(EncodingError::Conversion, '', $e);
        }
    }

    /**
     * @return array{encoding: string, source: EncodingSource}
     */
    private function detect(string $bytes, string $contentType): array
    {
        $bom = $this->detectUtf8Bom($bytes);
        if ($bom !== null) {
            return $bom;
        }

        $httpCharset = $this->parseHttpCharset($contentType);
        if ($httpCharset !== null) {
            return ['encoding' => $httpCharset, 'source' => EncodingSource::HttpHeader];
        }

        $metaCharset = $this->parseMetaCharset($bytes);
        if ($metaCharset !== null) {
            return ['encoding' => $metaCharset, 'source' => EncodingSource::Meta];
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return ['encoding' => 'UTF-8', 'source' => EncodingSource::Utf8Default];
        }

        throw new EncodingNormalizerException(EncodingError::Undeclared);
    }

    /**
     * @return array{encoding: string, source: EncodingSource}|null
     */
    private function detectUtf8Bom(string $bytes): ?array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return ['encoding' => 'UTF-8', 'source' => EncodingSource::Bom];
        }

        return null;
    }

    /**
     * This helper reads a charset parameter from the HTTP Content-Type header.
     */
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

    /**
     * This helper reads a charset from a meta tag in the first 1024 body bytes.
     */
    private function parseMetaCharset(string $bytes): ?string
    {
        $prescan = substr($bytes, 0, self::META_PRESCAN_BYTES);
        [$charset] = ContentTypeExtractor::getContentTypeFromHtml($prescan, 'utf-8');
        if ($charset === null || trim($charset) === '') {
            return null;
        }

        return $charset;
    }

    /**
     * This helper returns UTF-8 bytes for a declared encoding, and it throws when the bytes are not valid.
     */
    private function convertStrict(string $bytes, string $fromEncoding): string
    {
        if ($this->isUtf8Name($fromEncoding)) {
            if (!mb_check_encoding($bytes, 'UTF-8')) {
                throw new EncodingNormalizerException(EncodingError::Conversion);
            }

            return $bytes;
        }

        return $this->converter->convert($bytes, $fromEncoding);
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
}
