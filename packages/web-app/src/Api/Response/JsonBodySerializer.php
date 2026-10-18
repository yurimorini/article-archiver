<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Response;

use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Writes every API body as JSON. It is the only place that sets the JSON options.
 */
final readonly class JsonBodySerializer
{
    /**
     * Creates the serializer around the framework serializer.
     */
    public function __construct(private SerializerInterface $serializer)
    {
    }

    /**
     * Returns the JSON text of the body.
     */
    public function serialize(object $body): string
    {
        return $this->serializer->serialize($body, 'json', [
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ]);
    }
}
