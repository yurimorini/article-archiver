<?php

declare(strict_types=1);

namespace Yumo\LogRead\ArticleExtractor;

/**
 * This class holds one piece of metadata as plain text, with tags already removed.
 *
 * Store and log `raw()`. Use `html()` only when the text is placed in an HTML
 * body or attribute. The two forms differ: `html()` is escaped, and saving that
 * escaped string would encode it a second time on the next read.
 *
 * `$title = PlainText::fromUntrusted($articleTitle);`
 */
final readonly class PlainText
{
    /**
     * This constructor stores text that has already been stripped and trimmed.
     */
    private function __construct(
        /** This value holds the tag-stripped UTF-8 text. */
        private string $value,
    ) {
    }

    /**
     * This method builds plain text from untrusted metadata, stripping tags and trimming whitespace.
     *
     * An empty result is kept. Call `fromUntrustedNullable()` when a missing excerpt or site name should be null.
     */
    public static function fromUntrusted(string $value): self
    {
        return new self(trim(strip_tags($value)));
    }

    /**
     * This method builds plain text from optional metadata, returning null when the value is missing or blank after stripping.
     */
    public static function fromUntrustedNullable(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $text = self::fromUntrusted($value);
        if ($text->raw() === '') {
            return null;
        }

        return $text;
    }

    /**
     * This method returns the tag-stripped text for storage, JSON, or logs.
     */
    public function raw(): string
    {
        return $this->value;
    }

    /**
     * This method returns the text escaped for an HTML body or attribute.
     *
     * The escaping uses `ENT_QUOTES | ENT_SUBSTITUTE` and UTF-8. Do not store this return value.
     */
    public function html(): string
    {
        return htmlspecialchars($this->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * This method returns the same string as `raw()` so logs and JSON do not persist the escaped form by accident.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
