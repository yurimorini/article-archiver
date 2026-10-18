<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Controller;

use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Yumo\Eleanor\Api\Dto\CreateArchiveBody;
use Yumo\Eleanor\Api\Response\ArchiveResponse;
use Yumo\Eleanor\Api\Response\Reply;
use Yumo\Eleanor\Archive\ArchiveOutcome;
use Yumo\Eleanor\Archive\ArchiveResult;
use Yumo\Eleanor\Archive\ArchiveService;

/**
 * HTTP adapter for the archive command.
 *
 * It returns a `Reply` for the success and does not catch `ArchiveException`.
 * The kernel turns the reply into the response and translates the failure.
 */
final class CreateArchiveController
{
    /**
     * Archives the URL of the body and answers 201 for a new archive or 200 for one already stored.
     *
     * `ArchiveServiceResolver` supplies `$archives` for the current owner.
     *
     * @return Reply<ArchiveResponse>
     */
    #[Route('/archives', name: 'create_archive', methods: ['POST'], format: 'json')]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: 400)]
        CreateArchiveBody $body,
        ArchiveService $archives,
    ): Reply {
        $url = $body->url ?? throw new \LogicException('NotNull guarantees url.');
        $result = $archives->archive($url);

        return new Reply(
            $this->status($result),
            new ArchiveResponse($result->archive->url, $result->archive->title),
        );
    }

    private function status(ArchiveResult $result): int
    {
        return $result->outcome === ArchiveOutcome::Written ? 201 : 200;
    }
}
