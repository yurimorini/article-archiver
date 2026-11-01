<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Yumo\Eleanor\Api\ArchivePageQueryFactory;
use Yumo\Eleanor\Api\Dto\ListArchivesQuery;
use Yumo\Eleanor\Api\Response\ArchiveListDocuments;
use Yumo\Eleanor\Api\Response\ArchiveListResponse;
use Yumo\Eleanor\Api\Response\Reply;
use Yumo\Eleanor\Archive\ArchiveListService;

/**
 * HTTP adapter for one page of the caller's archives.
 *
 * It returns a `Reply` and does not catch `InvalidArchiveQuery`.
 * The kernel turns the reply into the response and translates the failure.
 */
final class ListArchivesController
{
    /**
     * Creates the controller from the query factory and the list documents.
     */
    public function __construct(
        private readonly ArchivePageQueryFactory $queries,
        private readonly ArchiveListDocuments $documents,
    ) {
    }

    /**
     * Returns one page of the caller's archives.
     *
     * `ListArchivesQueryResolver` supplies `$query`. `ArchiveListServiceResolver` supplies `$archives`.
     * `HEAD` is declared beside `GET` because Symfony puts only declared methods in `Allow`.
     *
     * @return Reply<ArchiveListResponse>
     */
    #[Route('/archives', name: 'list_archives', methods: ['GET', 'HEAD'], format: 'json')]
    public function list(ListArchivesQuery $query, ArchiveListService $archives): Reply
    {
        $query = $this->queries->from($query);
        $page = $archives->page($query);

        return new Reply(200, $this->documents->response($page));
    }
}
