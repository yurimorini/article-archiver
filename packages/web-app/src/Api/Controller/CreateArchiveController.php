<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Yumo\Eleanor\Api\Dto\CreateArchiveBody;
use Yumo\Eleanor\Api\Owner\OwnerProvider;
use Yumo\Eleanor\Archive\ArchiveOutcome;
use Yumo\Eleanor\Archive\ArchiveResult;
use Yumo\Eleanor\Archive\ArchiveServiceFactory;

/**
 * HTTP adapter for the archive command.
 *
 * It does not catch `ArchiveException`. A later slice translates that failure.
 */
final class CreateArchiveController
{
    public function __construct(
        /** The factory that builds the service of one owner. */
        private ArchiveServiceFactory $archives,
        /** The source of the owner of the current call. */
        private OwnerProvider $owners,
    ) {
    }

    /**
     * Archives the URL of the body and answers 201 for a new archive or 200 for one already stored.
     */
    #[Route('/archives', name: 'create_archive', methods: ['POST'], format: 'json')]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: 400)]
        CreateArchiveBody $body,
    ): JsonResponse {
        $url = $body->url ?? throw new \LogicException('NotNull guarantees url.');
        $result = $this->archives->create($this->owners->owner())->archive($url);

        return JsonResponse::fromJsonString($this->json($result), $this->status($result));
    }

    private function status(ArchiveResult $result): int
    {
        return $result->outcome === ArchiveOutcome::Written ? 201 : 200;
    }

    private function json(ArchiveResult $result): string
    {
        return json_encode(
            ['url' => $result->archive->url, 'title' => $result->archive->title],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
