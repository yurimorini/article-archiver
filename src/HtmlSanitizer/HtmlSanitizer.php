<?php

declare(strict_types=1);

namespace Yumo\LogRead\HtmlSanitizer;

use HTMLPurifier;
use HTMLPurifier_Config;
use HTMLPurifier_Exception;
use Yumo\LogRead\ArticleExtractor\ReadableDocument;

/**
 * This class purifies extracted article HTML and returns a document that is safe to store or emit as HTML.
 *
 * Only the article fragment is purified. Title, excerpt, and site name are copied through unchanged.
 * Scripts, iframes, event handlers, and URLs whose scheme is not allowed are stripped. That is a
 * successful result, including when nothing remains.
 *
 * `$safe = (new HtmlSanitizer())->purify($readableDocument);`
 */
final class HtmlSanitizer
{
    /** This value holds the scheme allowlist and the debug switch. */
    private PurifyPolicy $policy;

    /** This value is the directory where Purifier writes its definition cache when debug is off. */
    private string $definitionCachePath;

    /**
     * This constructor stores the policy and the definition-cache directory.
     *
     * When `$policy` is omitted, the MVP allowlist is used and debug is off.
     * When `$definitionCachePath` is omitted, definitions are stored under the system temp directory.
     */
    public function __construct(
        ?PurifyPolicy $policy = null,
        ?string $definitionCachePath = null,
    ) {
        $this->policy = $policy ?? new PurifyPolicy();
        $this->definitionCachePath = $definitionCachePath ?? sys_get_temp_dir() . '/log-read-htmlpurifier';
    }

    /**
     * This method purifies `$document->content` and copies the document’s metadata onto the result.
     *
     * @throws HtmlSanitizerException When the purifier config cannot be built or purification throws
     */
    public function purify(ReadableDocument $document): SafeDocument
    {
        $config = $this->config();

        try {
            $html = (new HTMLPurifier($config))->purify($document->content);
        } catch (HTMLPurifier_Exception $exception) {
            throw new HtmlSanitizerException(HtmlSanitizerError::Configuration, '', $exception);
        } catch (\Throwable $exception) {
            throw new HtmlSanitizerException(HtmlSanitizerError::Unexpected, '', $exception);
        }

        return new SafeDocument(
            $html,
            $document->title,
            $document->excerpt,
            $document->siteName,
            $document->sourceUrl,
        );
    }

    /**
     * This method builds the purifier config: UTF-8, a fixed XHTML doctype, and the policy’s scheme lookup.
     */
    private function config(): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
        $config->set('HTML.Trusted', false);
        $config->set('URI.AllowedSchemes', $this->schemeLookup());
        $config->set('URI.OverrideAllowedSchemes', false);
        $config->set('Cache.SerializerPath', $this->definitionCachePath);

        return $config;
    }

    /**
     * This method turns the policy’s scheme list into the lookup map Purifier expects.
     *
     * @return array<string, true>
     */
    private function schemeLookup(): array
    {
        $lookup = [];
        foreach ($this->policy->allowedSchemes as $scheme) {
            $lookup[$scheme] = true;
        }

        return $lookup;
    }
}
