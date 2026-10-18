<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Response;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Turns a reply into the HTTP response. Success and error replies share this path.
 */
final readonly class ReplyResponder
{
    /**
     * Creates the responder.
     */
    public function __construct(private JsonBodySerializer $serializer)
    {
    }

    /**
     * Builds the JSON response with the reply's status and the given headers.
     *
     * @param Reply<object> $reply
     * @param array<string, string|list<string>> $headers
     */
    public function respond(Reply $reply, array $headers = []): JsonResponse
    {
        $json = $this->serializer->serialize($reply->body);

        return JsonResponse::fromJsonString($json, $reply->status, $headers);
    }
}
