<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

/**
 * Fetches one URL and returns the purified article.
 *
 * It exists so the archive command does not depend on a final library class that tests cannot replace.
 */
interface ArticleFetcher
{
    /**
     * Fetches `$url`. A failure is thrown as `ArchiveException`.
     *
     * @throws ArchiveException
     */
    public function fetch(UrlPointer $url): Article;
}
