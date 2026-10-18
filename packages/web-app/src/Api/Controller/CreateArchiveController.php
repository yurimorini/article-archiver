<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Controller;

use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Yumo\Eleanor\Api\Dto\CreateArchiveBody;
use Yumo\Eleanor\Api\Error\RequestFailure;
use Yumo\Eleanor\Api\Response\ArchiveResponse;
use Yumo\Eleanor\Api\Response\Reply;
use Yumo\Eleanor\Archive\ArchiveFailure;
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
    #[OA\Post(
        path: '/api/archives',
        operationId: 'createArchive',
        summary: 'Archive the page at a URL.',
        description: 'Answers 201 for a new archive and 200 for a page already archived.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: CreateArchiveBody::class),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The page was already archived.',
                content: new OA\JsonContent(ref: ArchiveResponse::class),
            ),
            new OA\Response(
                response: 201,
                description: 'The page was archived.',
                content: new OA\JsonContent(ref: ArchiveResponse::class),
            ),
            new OA\Response(
                response: 400,
                description: 'The request or the URL was refused.',
                content: new OA\JsonContent(
                    required: ['code', 'message'],
                    properties: [
                        new OA\Property(property: 'code', type: 'string', enum: [
                            RequestFailure::InvalidJson->value,
                            RequestFailure::InvalidBody->value,
                            ArchiveFailure::UrlSyntax->value,
                            ArchiveFailure::UrlPolicy->value,
                            ArchiveFailure::UrlRejected->value,
                        ]),
                        new OA\Property(property: 'message', type: 'string'),
                    ],
                ),
            ),
            new OA\Response(
                response: 415,
                description: 'The request is not sent as JSON.',
                content: new OA\JsonContent(
                    required: ['code', 'message'],
                    properties: [
                        new OA\Property(property: 'code', type: 'string', enum: [
                            RequestFailure::UnsupportedMediaType->value,
                        ]),
                        new OA\Property(property: 'message', type: 'string'),
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'The page cannot be archived.',
                content: new OA\JsonContent(
                    required: ['code', 'message'],
                    properties: [
                        new OA\Property(property: 'code', type: 'string', enum: [
                            ArchiveFailure::ExtractNoContent->value,
                            ArchiveFailure::SanitizedEmpty->value,
                            ArchiveFailure::Redirect->value,
                            ArchiveFailure::ContentType->value,
                            ArchiveFailure::BodyTooLarge->value,
                        ]),
                        new OA\Property(property: 'message', type: 'string'),
                    ],
                ),
            ),
            new OA\Response(
                response: 500,
                description: 'Eleanor failed.',
                content: new OA\JsonContent(
                    required: ['code', 'message'],
                    properties: [
                        new OA\Property(property: 'code', type: 'string', enum: [
                            ArchiveFailure::Encoding->value,
                            ArchiveFailure::Extract->value,
                            ArchiveFailure::Sanitize->value,
                            ArchiveFailure::NotWritten->value,
                            ArchiveFailure::Unexpected->value,
                        ]),
                        new OA\Property(property: 'message', type: 'string'),
                    ],
                ),
            ),
            new OA\Response(
                response: 502,
                description: "The page's site could not be reached or answered with an error status.",
                content: new OA\JsonContent(
                    required: ['code', 'message'],
                    properties: [
                        new OA\Property(property: 'code', type: 'string', enum: [
                            ArchiveFailure::Transport->value,
                            ArchiveFailure::UpstreamStatus->value,
                        ]),
                        new OA\Property(property: 'message', type: 'string'),
                    ],
                ),
            ),
        ],
    )]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: 400)]
        CreateArchiveBody $body,
        ArchiveService $archives,
    ): Reply {
        $url = $body->url ?? throw new \LogicException('NotNull guarantees url.');
        $result = $archives->archive($url);

        return new Reply(
            $this->status($result),
            new ArchiveResponse($result->summary->url, $result->summary->title),
        );
    }

    private function status(ArchiveResult $result): int
    {
        return $result->outcome === ArchiveOutcome::Written ? 201 : 200;
    }
}
