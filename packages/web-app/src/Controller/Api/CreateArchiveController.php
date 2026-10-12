<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP adapter for the empty archive route.
 */
final class CreateArchiveController
{
    /**
     * Returns the empty-route stub and does not read the request.
     */
    #[Route('/archives', name: 'create_archive', methods: ['POST'], format: 'json')]
    public function create(): JsonResponse
    {
        return JsonResponse::fromJsonString('{"ok":true}');
    }
}
