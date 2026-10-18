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
    private string $url;

    public function __construct(string $url)
    {
        $this->url = trim($url, "\0");
    }

    /**
     * Returns the stored URL string.
     */
    public function toString(): string
    {
        return $this->url;
    }
}
