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
            $html = new HTMLPurifier($config)->purify($document->content);
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
     * This method builds the purifier config for the stored policy.
     *
     * Debug skips the definition cache and collects errors inside the purifier.
     * Those messages are not returned and do not change the fragment.
     */
    private function config(): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
        $config->set('HTML.Trusted', false);
        $config->set('URI.AllowedSchemes', $this->schemeLookup());
        // HTMLPurifier's scheme registry is process-wide; keep this false or an earlier config can leave a scheme this policy rejected.
        $config->set('URI.OverrideAllowedSchemes', false);

        if ($this->policy->debug) {
            $config->set('Cache.DefinitionImpl', null);
            $config->set('Core.CollectErrors', true);

            return $config;
        }

        $this->prepareDefinitionCache();
        $config->set('Cache.SerializerPath', $this->definitionCachePath);

        return $config;
    }

    /**
     * This method creates the definition-cache directory when it is missing.
     *
     * Purifier only warns when the directory is absent, and still returns HTML.
     * Callers need a hard failure instead, so this method throws before `purify()`.
     */
    private function prepareDefinitionCache(): void
    {
        $path = $this->definitionCachePath;
        if ($path === '' || (file_exists($path) && !is_dir($path)) || (is_dir($path) && !is_writable($path))) {
            throw new HtmlSanitizerException(
                HtmlSanitizerError::Configuration,
                'HTML purifier definition cache path is not usable',
            );
        }
        if (is_dir($path)) {
            return;
        }

        // mkdir warns on failure. The warning is suppressed so the thrown exception is the only signal.
        if (!@mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new HtmlSanitizerException(
                HtmlSanitizerError::Configuration,
                'HTML purifier definition cache path is not usable',
            );
        }
        if (!is_writable($path)) {
            throw new HtmlSanitizerException(
                HtmlSanitizerError::Configuration,
                'HTML purifier definition cache path is not usable',
            );
        }
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
