<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HtmlSanitizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\PlainText;
use Yumo\LogRead\ArticleExtractor\ReadableDocument;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizer;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizerError;
use Yumo\LogRead\HtmlSanitizer\HtmlSanitizerException;
use Yumo\LogRead\HtmlSanitizer\PurifyPolicy;
use Yumo\LogRead\HtmlSanitizer\SafeDocument;

final class HtmlSanitizerTest extends TestCase
{
    /** This value is a writable directory passed as the definition-cache path. */
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . '/log-read-htmlpurifier-' . bin2hex(random_bytes(4));
        mkdir($this->cachePath, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->cachePath);
    }

    #[DataProvider('markup')]
    public function test_purify_keeps_article_markup_and_drops_unsafe_constructs(string $content, string $expected): void
    {
        $safe = $this->sanitizer()->purify($this->article($content));

        self::assertInstanceOf(SafeDocument::class, $safe);
        self::assertSame($expected, $safe->html);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function markup(): array
    {
        return [
            'script' => ['<p>x</p><script>alert(1)</script>', '<p>x</p>'],
            'javascript url' => ['<a href="javascript:alert(1)">x</a>', '<a>x</a>'],
            'https link' => ['<a href="https://example.com/a">x</a>', '<a href="https://example.com/a">x</a>'],
            'mailto' => ['<a href="mailto:a@b.c">x</a>', '<a href="mailto:a@b.c">x</a>'],
            'ftp' => ['<a href="ftp://example.com/f">x</a>', '<a>x</a>'],
            'file url' => ['<a href="file:///etc/passwd">x</a>', '<a>x</a>'],
            'https image' => [
                '<img src="https://example.com/x.png" alt="x">',
                '<img src="https://example.com/x.png" alt="x" />',
            ],
            'iframe' => ['<p>ok</p><iframe src="https://example.com"></iframe>', '<p>ok</p>'],
            'data image' => ['<img src="data:image/png;base64,aaa" alt="x">', ''],
            'onclick' => ['<p onclick="alert(1)">x</p>', '<p>x</p>'],
        ];
    }

    public function test_https_only_policy_strips_mailto_and_keeps_https(): void
    {
        $sanitizer = new HtmlSanitizer(new PurifyPolicy(['https']), $this->cachePath);
        $safe = $sanitizer->purify($this->article(
            '<a href="mailto:a@b.c">x</a><a href="https://example.com/a">y</a>',
        ));

        self::assertSame('<a>x</a><a href="https://example.com/a">y</a>', $safe->html);
    }

    public function test_copies_metadata_instances_and_source_url(): void
    {
        $document = $this->article('<p>x</p><script>alert(1)</script>');
        $safe = $this->sanitizer()->purify($document);

        self::assertSame('<p>x</p>', $safe->html);
        self::assertSame($document->title, $safe->title);
        self::assertSame('Tom & Jerry', $safe->title->raw());
        self::assertSame($document->excerpt, $safe->excerpt);
        self::assertSame($document->siteName, $safe->siteName);
        self::assertSame('https://example.com/article', $safe->sourceUrl);
    }

    public function test_copies_null_excerpt_and_site_name(): void
    {
        $document = new ReadableDocument(
            PlainText::fromUntrusted('Title'),
            null,
            null,
            '<p>x</p>',
            'https://example.com/a',
        );
        $safe = $this->sanitizer()->purify($document);

        self::assertSame($document->title, $safe->title);
        self::assertNull($safe->excerpt);
        self::assertNull($safe->siteName);
        self::assertSame('https://example.com/a', $safe->sourceUrl);
    }

    public function test_whitespace_only_fragment_is_returned_unchanged(): void
    {
        $safe = $this->sanitizer()->purify($this->article('   '));

        self::assertSame('   ', $safe->html);
    }

    public function test_script_only_fragment_is_an_empty_safe_document(): void
    {
        $safe = $this->sanitizer()->purify($this->article('<script>alert(1)</script>'));

        self::assertSame('', $safe->html);
    }

    public function test_missing_cache_directory_is_created(): void
    {
        $path = sys_get_temp_dir() . '/log-read-htmlpurifier-missing-' . bin2hex(random_bytes(4));
        self::assertDirectoryDoesNotExist($path);

        try {
            $safe = new HtmlSanitizer(new PurifyPolicy(), $path)->purify($this->article('<p>x</p>'));

            self::assertSame('<p>x</p>', $safe->html);
            self::assertDirectoryExists($path);
        } finally {
            $this->removeTree($path);
        }
    }

    public function test_file_cache_path_throws_configuration(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lr-hp-');
        self::assertIsString($path);

        try {
            new HtmlSanitizer(new PurifyPolicy(), $path)->purify($this->article('<p>x</p>'));
            self::fail('A file path must not be used as the definition cache');
        } catch (HtmlSanitizerException $exception) {
            self::assertSame(HtmlSanitizerError::Configuration, $exception->error);
            self::assertSame('HTML purifier definition cache path is not usable', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        } finally {
            $this->removeTree($path);
        }
    }

    public function test_unwritable_cache_directory_throws_configuration(): void
    {
        $path = sys_get_temp_dir() . '/log-read-htmlpurifier-ro-' . bin2hex(random_bytes(4));
        mkdir($path, 0o775, true);
        chmod($path, 0o555);
        if (is_writable($path)) {
            $this->removeTree($path);
            self::markTestSkipped('This process can still write to a mode 0555 directory');
        }

        try {
            new HtmlSanitizer(new PurifyPolicy(), $path)->purify($this->article('<p>x</p>'));
            self::fail('An unwritable directory must not be used as the definition cache');
        } catch (HtmlSanitizerException $exception) {
            self::assertSame(HtmlSanitizerError::Configuration, $exception->error);
            self::assertSame('HTML purifier definition cache path is not usable', $exception->getMessage());
        } finally {
            chmod($path, 0o775);
            $this->removeTree($path);
        }
    }

    public function test_debug_does_not_require_a_cache_directory_and_strips_the_same_way(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'lr-hp-debug-');
        self::assertIsString($file);
        $content = '<p>x</p><script>alert(1)</script>';

        try {
            $debugged = new HtmlSanitizer(new PurifyPolicy(debug: true), $file)->purify($this->article($content));
            $cached = $this->sanitizer()->purify($this->article($content));

            self::assertSame('<p>x</p>', $debugged->html);
            self::assertSame($cached->html, $debugged->html);
            self::assertFileExists($file);
        } finally {
            $this->removeTree($file);
        }
    }

    /**
     * This method builds a sanitizer that writes definitions into the per-test cache directory.
     */
    private function sanitizer(): HtmlSanitizer
    {
        return new HtmlSanitizer(new PurifyPolicy(), $this->cachePath);
    }

    /**
     * This method builds an extracted article whose title still contains an ampersand after tags are stripped.
     *
     * The title starts as `Tom & <b>Jerry</b>`. `PlainText` stores `Tom & Jerry`.
     * Purification must keep that same instance. Re-parsing the title as HTML would change the ampersand.
     */
    private function article(string $content): ReadableDocument
    {
        return new ReadableDocument(
            PlainText::fromUntrusted('Tom & <b>Jerry</b>'),
            PlainText::fromUntrustedNullable('Short & sweet'),
            PlainText::fromUntrustedNullable('Example News'),
            $content,
            'https://example.com/article',
        );
    }

    /**
     * This method deletes a cache directory created for one test, including files Purifier wrote inside it.
     */
    private function removeTree(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
