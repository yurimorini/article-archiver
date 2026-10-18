<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

/**
 * Names why a page could not be archived.
 *
 * Each value is the stable code a client reads, so renaming a value changes the public API.
 */
enum ArchiveFailure: string
{
    /** The URL string is missing, malformed, or has no host. */
    case UrlSyntax = 'url_syntax';

    /** The URL scheme or credentials are not allowed. */
    case UrlPolicy = 'url_policy';

    /** The URL was refused by the address check, or the library gave no finer reason. */
    case UrlRejected = 'url_rejected';

    /** The page has no article. */
    case ExtractNoContent = 'extract_no_content';

    /** The article is empty after purification. */
    case SanitizedEmpty = 'sanitized_empty';

    /** The page redirects. */
    case Redirect = 'redirect';

    /** The page is not HTML. */
    case ContentType = 'content_type';

    /** The page is larger than the size limit. */
    case BodyTooLarge = 'body_too_large';

    /** The site of the page could not be reached. */
    case Transport = 'transport';

    /** The site of the page answered with an error status. */
    case UpstreamStatus = 'upstream_status';

    /** The character encoding of the page could not be read. */
    case Encoding = 'encoding';

    /** The article could not be extracted. */
    case Extract = 'extract';

    /** The article could not be purified. */
    case Sanitize = 'sanitize';

    /** The library failed in a way it did not name. */
    case Unexpected = 'unexpected';
}
