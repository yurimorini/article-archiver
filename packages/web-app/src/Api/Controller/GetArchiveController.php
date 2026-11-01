<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Yumo\Eleanor\Api\Dto\ArchiveReadQuery;
use Yumo\Eleanor\Api\Response\ArchiveDetailResponse;
use Yumo\Eleanor\Api\Response\Reply;
use Yumo\Eleanor\Archive\ArchiveId;
use Yumo\Eleanor\Archive\ArchiveReadService;
use Yumo\Eleanor\Archive\ArchiveSummary;
use Yumo\Eleanor\Archive\InvalidArchiveQuery;
use Yumo\Eleanor\Archive\MissingArchive;

/**
 * HTTP adapter for one archive.
 *
 * The JSON action returns a `Reply`. The HTML action returns a framework response.
 * Neither catches `InvalidArchiveQuery` or `MissingArchive`.
 * The kernel turns the reply into the response and translates the failure.
 */
final class GetArchiveController
{
    /**
     * Returns one archive as metadata, or as metadata plus HTML when `includeContent` is the string `true`.
     *
     * `ArchiveReadQueryResolver` supplies `$query`. `OwnerScopedServiceResolver` supplies `$archives`.
     * `HEAD` is declared beside `GET` because Symfony puts only declared methods in `Allow`.
     *
     * @return Reply<ArchiveSummary|ArchiveDetailResponse>
     */
    #[Route(
        '/archives/{id}',
        name: 'read_archive',
        requirements: ['id' => '(?!.*\.(?:json|html)$)[^/]+'],
        methods: ['GET', 'HEAD'],
        format: 'json',
    )]
    public function read(string $id, ArchiveReadQuery $query, ArchiveReadService $archives): Reply
    {
        $archiveId = ArchiveId::from($id);

        if ($query->includeContent !== null && $query->includeContent !== 'true') {
            throw new InvalidArchiveQuery();
        }

        if ($query->includeContent === null) {
            $summary = $archives->summary($archiveId);

            if ($summary === null) {
                throw new MissingArchive();
            }

            return new Reply(200, $summary);
        }

        $archive = $archives->read($archiveId);

        if ($archive === null) {
            throw new MissingArchive();
        }

        return new Reply(200, ArchiveDetailResponse::fromArchive($archive));
    }

    /**
     * Returns the stored HTML of one archive.
     *
     * The query string is not read. `OwnerScopedServiceResolver` supplies `$archives`.
     * `HEAD` is declared beside `GET` because Symfony puts only declared methods in `Allow`.
     */
    #[Route(
        '/archives/{id}.html',
        name: 'read_archive_html',
        requirements: ['id' => '[^/.]+'],
        methods: ['GET', 'HEAD'],
    )]
    public function html(string $id, ArchiveReadService $archives): Response
    {
        $html = $archives->html(ArchiveId::from($id));

        if ($html === null) {
            throw new MissingArchive();
        }

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
