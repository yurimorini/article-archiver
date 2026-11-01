<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Response;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Yumo\Eleanor\Archive\ArchivePage;
use Yumo\Eleanor\Archive\ArchiveSummary;

/**
 * Builds the JSON document for one archive page and the link to the next page.
 *
 * It does not read the store. `next` is set only when `page * size < total`.
 */
final readonly class ArchiveListDocuments
{
    /**
     * Creates the document builder from the router.
     */
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    /**
     * Copies the page into the list document. The site name is written as `site`.
     */
    public function response(ArchivePage $page): ArchiveListResponse
    {
        return new ArchiveListResponse(
            $page->sort->value,
            $page->dir->value,
            $page->page,
            $page->size,
            $this->items($page),
            $page->total,
            $this->next($page),
        );
    }

    /**
     * @return list<ArchiveListItemResponse>
     */
    private function items(ArchivePage $page): array
    {
        $items = [];
        foreach ($page->items as $summary) {
            $items[] = $this->item($summary);
        }

        return $items;
    }

    /**
     * Copies one summary. `site` is `siteName`, and null stays null.
     */
    private function item(ArchiveSummary $summary): ArchiveListItemResponse
    {
        return new ArchiveListItemResponse(
            $summary->id,
            $summary->url,
            $summary->title,
            $summary->excerpt,
            $summary->siteName,
            $summary->archived,
        );
    }

    /**
     * The absolute path of the following page, or null when this page already reaches the total.
     */
    private function next(ArchivePage $page): ?string
    {
        if ($page->page * $page->size >= $page->total) {
            return null;
        }

        return $this->urls->generate(
            'list_archives',
            [
                'sort' => $page->sort->value,
                'dir' => $page->dir->value,
                'page' => $page->page + 1,
                'size' => $page->size,
            ],
            UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }
}
