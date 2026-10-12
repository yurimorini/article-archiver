<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Yumo\Eleanor\Api\Dto\CreateArchiveBody;

/**
 * HTTP adapter for the empty archive route.
 */
final class CreateArchiveController
{
    /**
     * Returns the empty-route stub and does not read the mapped body.
     */
    #[Route('/archives', name: 'create_archive', methods: ['POST'], format: 'json')]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: 400)]
        CreateArchiveBody $body,
    ): JsonResponse {
        return JsonResponse::fromJsonString('{"ok":true}');
    }
}
