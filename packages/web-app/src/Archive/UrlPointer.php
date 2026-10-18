<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Archive;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The URL string that names a page for archiving.
 */
#[Exclude]
final readonly class UrlPointer
{
    /**
     * The byte stripped from both ends of a URL, and the byte between an owner and a URL in a lock key.
     */
    public const string SEPARATOR = "\0";

    private string $url;

    public function __construct(string $url)
    {
        $this->url = trim($url, self::SEPARATOR);
    }

    /**
     * Returns the stored URL string.
     */
    public function toString(): string
    {
        return $this->url;
    }
}
