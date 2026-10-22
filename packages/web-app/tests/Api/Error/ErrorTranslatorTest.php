<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Api\Error;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Yumo\Eleanor\Api\Error\ErrorTranslator;
use Yumo\Eleanor\Archive\ArchiveException;
use Yumo\Eleanor\Archive\ArchiveFailure;

/**
 * Proves that every throwable becomes one status, one stable code, and one fixed message.
 */
final class ErrorTranslatorTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable, int, string, string}>
     */
    public static function translations(): iterable
    {
        yield 'invalid_json' => [
            new BadRequestHttpException('x', new NotEncodableValueException('x')),
            400,
            'invalid_json',
            'The request body is not valid JSON.',
        ];
        yield 'invalid_body with previous' => [
            new BadRequestHttpException('x', new \RuntimeException()),
            400,
            'invalid_body',
            'The request body must be a JSON object with a string url.',
        ];
        yield 'invalid_body without previous' => [
            new BadRequestHttpException(),
            400,
            'invalid_body',
            'The request body must be a JSON object with a string url.',
        ];
        yield 'url_syntax' => [
            new ArchiveException(ArchiveFailure::UrlSyntax),
            400,
            'url_syntax',
            'The URL is not valid.',
        ];
        yield 'url_policy' => [
            new ArchiveException(ArchiveFailure::UrlPolicy),
            400,
            'url_policy',
            'The URL is not allowed.',
        ];
        yield 'url_rejected' => [
            new ArchiveException(ArchiveFailure::UrlRejected),
            400,
            'url_rejected',
            'The URL was refused.',
        ];
        yield 'not_found' => [
            new NotFoundHttpException(),
            404,
            'not_found',
            'The path does not exist.',
        ];
        yield 'method_not_allowed' => [
            new MethodNotAllowedHttpException(['POST']),
            405,
            'method_not_allowed',
            'The method is not allowed for this path.',
        ];
        yield 'unsupported_media_type' => [
            new UnsupportedMediaTypeHttpException(),
            415,
            'unsupported_media_type',
            'The request must be sent as application/json.',
        ];
        yield 'unauthenticated' => [
            new CustomUserMessageAuthenticationException('Unauthenticated.'),
            401,
            'unauthenticated',
            'The request is not authenticated.',
        ];
        yield 'extract_no_content' => [
            new ArchiveException(ArchiveFailure::ExtractNoContent),
            422,
            'extract_no_content',
            'The page has no article.',
        ];
        yield 'sanitized_empty' => [
            new ArchiveException(ArchiveFailure::SanitizedEmpty),
            422,
            'sanitized_empty',
            'The article is empty after purification.',
        ];
        yield 'redirect' => [
            new ArchiveException(ArchiveFailure::Redirect),
            422,
            'redirect',
            'The page redirects. Send the final URL.',
        ];
        yield 'content_type' => [
            new ArchiveException(ArchiveFailure::ContentType),
            422,
            'content_type',
            'The page is not HTML.',
        ];
        yield 'body_too_large' => [
            new ArchiveException(ArchiveFailure::BodyTooLarge),
            422,
            'body_too_large',
            'The page is larger than the size limit.',
        ];
        yield 'transport' => [
            new ArchiveException(ArchiveFailure::Transport),
            502,
            'transport',
            "The page's site could not be reached.",
        ];
        yield 'upstream_status with number' => [
            new ArchiveException(ArchiveFailure::UpstreamStatus, upstreamStatus: 503),
            502,
            'upstream_status',
            "The page's site answered with status 503.",
        ];
        yield 'upstream_status without number' => [
            new ArchiveException(ArchiveFailure::UpstreamStatus),
            502,
            'upstream_status',
            "The page's site answered with an error status.",
        ];
        yield 'encoding' => [
            new ArchiveException(ArchiveFailure::Encoding),
            500,
            'encoding',
            "The page's character encoding could not be read.",
        ];
        yield 'extract' => [
            new ArchiveException(ArchiveFailure::Extract),
            500,
            'extract',
            'The article could not be extracted.',
        ];
        yield 'sanitize' => [
            new ArchiveException(ArchiveFailure::Sanitize),
            500,
            'sanitize',
            'The article could not be purified.',
        ];
        yield 'archive_not_written' => [
            new ArchiveException(ArchiveFailure::NotWritten, new \PDOException('disk full')),
            500,
            'archive_not_written',
            'The archive could not be stored.',
        ];
        yield 'unexpected archive failure' => [
            new ArchiveException(ArchiveFailure::Unexpected),
            500,
            'unexpected',
            'Something went wrong on the server.',
        ];
        yield 'unknown throwable' => [
            new \LogicException('boom'),
            500,
            'unexpected',
            'Something went wrong on the server.',
        ];
    }

    #[DataProvider('translations')]
    public function test_translates_the_throwable(\Throwable $error, int $status, string $code, string $message): void
    {
        $reply = new ErrorTranslator()->translate($error);

        self::assertSame($status, $reply->status);
        self::assertSame($code, $reply->body->code);
        self::assertSame($message, $reply->body->message);
    }

    public function test_other_http_errors_are_not_treated_as_bad_requests(): void
    {
        $reply = new ErrorTranslator()->translate(new ConflictHttpException());

        self::assertSame(500, $reply->status);
        self::assertSame('unexpected', $reply->body->code);
    }

    public function test_failure_text_never_reaches_the_body(): void
    {
        $translator = new ErrorTranslator();

        $archive = $translator->translate(
            new ArchiveException(ArchiveFailure::Transport, new \RuntimeException('connect to 10.0.0.5 refused')),
        );
        $unknown = $translator->translate(new \LogicException('/etc/secret'));
        $notWritten = $translator->translate(
            new ArchiveException(ArchiveFailure::NotWritten, new \PDOException('disk full')),
        );

        self::assertStringNotContainsString('10.0.0.5', json_encode($archive->body, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('/etc/secret', json_encode($unknown->body, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('disk full', json_encode($notWritten->body, JSON_THROW_ON_ERROR));
    }
}
