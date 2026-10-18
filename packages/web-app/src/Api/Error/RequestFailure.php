<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Error;

/**
 * Names why the framework refused a request before or around the archive use case.
 *
 * Each value is the stable code a client reads, so renaming a value changes the public API.
 */
enum RequestFailure: string
{
    /** The body is not valid JSON. */
    case InvalidJson = 'invalid_json';

    /** The body is JSON but does not have the expected shape. */
    case InvalidBody = 'invalid_body';

    /** No route matches the path. */
    case NotFound = 'not_found';

    /** The path exists but not for this method. */
    case MethodNotAllowed = 'method_not_allowed';

    /** The request is not sent as JSON. */
    case UnsupportedMediaType = 'unsupported_media_type';

    /**
     * Returns the HTTP status to answer with.
     */
    public function status(): int
    {
        return match ($this) {
            self::InvalidJson, self::InvalidBody => 400,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::UnsupportedMediaType => 415,
        };
    }

    /**
     * Returns the fixed message for people.
     */
    public function message(): string
    {
        return match ($this) {
            self::InvalidJson => 'The request body is not valid JSON.',
            self::InvalidBody => 'The request body must be a JSON object with a string url.',
            self::NotFound => 'The path does not exist.',
            self::MethodNotAllowed => 'The method is not allowed for this path.',
            self::UnsupportedMediaType => 'The request must be sent as application/json.',
        };
    }
}
