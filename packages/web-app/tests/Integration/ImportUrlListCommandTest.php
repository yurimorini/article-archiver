<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Yumo\Eleanor\Archive\ArchiveException;
use Yumo\Eleanor\Archive\ArchiveFailure;
use Yumo\Eleanor\Tests\Support\ArchiveDirectories;
use Yumo\Eleanor\Tests\Support\FakeArticleFetcher;
use Yumo\Eleanor\Tests\Support\PipelineFixtures;

/**
 * Proves `archive:import` through one console run.
 */
final class ImportUrlListCommandTest extends WebTestCase
{
    private string $previousCwd;

    private ApplicationTester $tester;

    protected function setUp(): void
    {
        ArchiveDirectories::isolate();
        parent::setUp();
        $cwd = getcwd();
        if ($cwd === false) {
            throw new \RuntimeException('The working directory could not be read.');
        }
        $this->previousCwd = $cwd;
        $work = sys_get_temp_dir() . '/eleanor-import-' . bin2hex(random_bytes(6));
        mkdir($work);
        chdir($work);
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        parent::tearDown();
    }

    public function test_an_empty_owner_fails_before_importing(): void
    {
        $exit = $this->runImport('unused.txt', ['--owner' => '']);

        self::assertSame(1, $exit);
        self::assertSame('', $this->tester->getDisplay());
        self::assertStringContainsString('The owner email is empty.', $this->tester->getErrorOutput());
        self::assertStringNotContainsString('Importing:', $this->tester->getErrorOutput());
    }

    public function test_a_missing_file_leaves_error_log(): void
    {
        file_put_contents('error.log', 'old');

        $exit = $this->runImport('missing.txt', ['--owner' => 'ada@example.com']);

        self::assertSame(1, $exit);
        self::assertSame('old', file_get_contents('error.log'));
        self::assertStringNotContainsString('Importing:', $this->tester->getDisplay());
        self::assertStringNotContainsString('Importing:', $this->tester->getErrorOutput());
        self::assertStringContainsString('The import file could not be read.', $this->tester->getErrorOutput());
    }

    public function test_a_directory_is_not_a_file(): void
    {
        file_put_contents('error.log', 'old');
        mkdir('not-a-file');

        $exit = $this->runImport('not-a-file', ['--owner' => 'ada@example.com']);

        self::assertSame(1, $exit);
        self::assertSame('old', file_get_contents('error.log'));
        self::assertStringNotContainsString('Importing:', $this->tester->getDisplay());
        self::assertStringNotContainsString('Importing:', $this->tester->getErrorOutput());
        self::assertStringContainsString('The import file could not be read.', $this->tester->getErrorOutput());
    }

    public function test_an_empty_file_deletes_error_log(): void
    {
        file_put_contents('error.log', 'old');
        file_put_contents('empty.txt', '');

        $exit = $this->runImport('empty.txt', ['--owner' => 'ada@example.com']);

        self::assertSame(0, $exit);
        self::assertSame(
            "Importing: empty.txt\n\nWritten 0\nAlready stored 0\nFailed 0\nSkipped 0\n",
            $this->tester->getDisplay(),
        );
        self::assertFileDoesNotExist('error.log');
        self::assertSame(0, $this->fetcher()->fetchCount());
    }

    public function test_one_url_is_archived(): void
    {
        file_put_contents('one.txt', "https://example.com/a\n");
        $this->fetcher()->willReturn('https://example.com/a', PipelineFixtures::article('Title'));

        $exit = $this->runImport('one.txt', ['--owner' => 'ada@example.com']);

        self::assertSame(0, $exit);
        self::assertStringContainsString(
            str_pad('ARCHIVED', 16) . 'https://example.com/a',
            $this->tester->getDisplay(),
        );
        self::assertStringContainsString(
            "Written 1\nAlready stored 0\nFailed 0\nSkipped 0\n",
            $this->tester->getDisplay(),
        );
        self::assertSame(1, $this->fetcher()->fetchCount('https://example.com/a'));
        self::assertFileDoesNotExist('error.log');
    }

    public function test_a_line_of_spaces_is_skipped(): void
    {
        file_put_contents('spaces.txt', "   \n");

        $exit = $this->runImport('spaces.txt', ['--owner' => 'ada@example.com']);

        self::assertSame(0, $exit);
        self::assertStringContainsString(str_pad('SKIPPED', 16) . "--\n", $this->tester->getDisplay());
        self::assertStringContainsString("Skipped 1\n", $this->tester->getDisplay());
        self::assertSame(0, $this->fetcher()->fetchCount());
    }

    public function test_a_rejected_url_is_skipped(): void
    {
        file_put_contents('bad.txt', "not-a-url\n");

        $exit = $this->runImport('bad.txt', ['--owner' => 'ada@example.com']);

        self::assertSame(0, $exit);
        self::assertStringContainsString(str_pad('SKIPPED', 16) . "not-a-url\n", $this->tester->getDisplay());
        self::assertStringContainsString("Skipped 1\n", $this->tester->getDisplay());
        self::assertSame(0, $this->fetcher()->fetchCount());
        self::assertFileDoesNotExist('error.log');
    }

    public function test_a_comma_stays_in_the_line(): void
    {
        file_put_contents('comma.txt', "not,a,url\n");

        $exit = $this->runImport('comma.txt', ['--owner' => 'ada@example.com']);

        self::assertSame(0, $exit);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString(str_pad('SKIPPED', 16) . "not,a,url\n", $display);
        self::assertSame(1, substr_count($display, 'SKIPPED'));
    }

    public function test_a_second_run_does_not_fetch(): void
    {
        file_put_contents('one.txt', "https://example.com/a\n");
        $this->fetcher()->willReturn('https://example.com/a', PipelineFixtures::article('Title'));
        $this->runImport('one.txt', ['--owner' => 'ada@example.com']);

        $exit = $this->runImport('one.txt', ['--owner' => 'ada@example.com']);

        self::assertSame(0, $exit);
        self::assertStringContainsString(
            str_pad('ALREADY STORED', 16) . 'https://example.com/a',
            $this->tester->getDisplay(),
        );
        self::assertStringContainsString("Already stored 1\n", $this->tester->getDisplay());
        self::assertSame(1, $this->fetcher()->fetchCount('https://example.com/a'));
    }

    public function test_two_copies_fetch_once(): void
    {
        file_put_contents('two.txt', "https://example.com/a\nhttps://example.com/a\n");
        $this->fetcher()->willReturn('https://example.com/a', PipelineFixtures::article('Title'));

        $exit = $this->runImport('two.txt', ['--owner' => 'ada@example.com']);

        self::assertSame(0, $exit);
        self::assertStringContainsString(
            str_pad('ARCHIVED', 16) . "https://example.com/a\n" . str_pad('ALREADY STORED', 16) . 'https://example.com/a',
            $this->tester->getDisplay(),
        );
        self::assertStringContainsString("Written 1\nAlready stored 1\n", $this->tester->getDisplay());
        self::assertSame(1, $this->fetcher()->fetchCount('https://example.com/a'));
    }

    public function test_scheme_case_is_kept(): void
    {
        $url = 'HTTPS://Example.com/A';
        file_put_contents('case.txt', $url . "\n");
        $this->fetcher()->willReturn($url, PipelineFixtures::article('Title'));

        $this->runImport('case.txt', ['--owner' => 'ada@example.com']);
        $first = $this->tester->getDisplay();
        $this->runImport('case.txt', ['--owner' => 'ada@example.com']);

        self::assertStringContainsString(str_pad('ARCHIVED', 16) . $url, $first);
        self::assertStringContainsString(str_pad('ALREADY STORED', 16) . $url, $this->tester->getDisplay());
        self::assertSame(1, $this->fetcher()->fetchCount($url));
    }

    public function test_crlf_does_not_stay_in_the_url(): void
    {
        file_put_contents('crlf.txt', "https://example.com/a\r\n");
        $this->fetcher()->willReturn('https://example.com/a', PipelineFixtures::article('Title'));

        $this->runImport('crlf.txt', ['--owner' => 'ada@example.com']);

        self::assertStringContainsString(
            str_pad('ARCHIVED', 16) . "https://example.com/a\n",
            $this->tester->getDisplay(),
        );
        self::assertStringNotContainsString("\r", $this->tester->getDisplay());
        self::assertSame(1, $this->fetcher()->fetchCount('https://example.com/a'));
    }

    public function test_owner_case_is_the_same_owner(): void
    {
        file_put_contents('owner.txt', "https://example.com/a\n");
        $this->fetcher()->willReturn('https://example.com/a', PipelineFixtures::article('Title'));
        $this->runImport('owner.txt', ['--owner' => 'Owner@Example.com']);

        $exit = $this->runImport('owner.txt', ['--owner' => 'owner@example.com']);

        self::assertSame(0, $exit);
        self::assertStringContainsString(
            str_pad('ALREADY STORED', 16) . 'https://example.com/a',
            $this->tester->getDisplay(),
        );
        self::assertSame(1, $this->fetcher()->fetchCount('https://example.com/a'));
    }

    public function test_a_failed_url_does_not_stop_the_next(): void
    {
        file_put_contents('two.txt', "https://example.com/a\nhttps://example.com/b\n");
        $this->fetcher()->willThrow('https://example.com/a', new ArchiveException(ArchiveFailure::Transport));
        $this->fetcher()->willReturn('https://example.com/b', PipelineFixtures::article('Title'));

        $exit = $this->runImport('two.txt', ['--owner' => 'ada@example.com']);

        $display = $this->tester->getDisplay();
        self::assertSame(1, $exit);
        self::assertStringContainsString(
            str_pad('ERROR', 16) . "https://example.com/a\n" . str_pad('ARCHIVED', 16) . 'https://example.com/b',
            $display,
        );
        self::assertStringContainsString("Written 1\nAlready stored 0\nFailed 1\n", $display);
        self::assertSame("https://example.com/a\ttransport\n", file_get_contents('error.log'));
        $cwd = getcwd();
        self::assertIsString($cwd);
        self::assertStringEndsWith("For error details see {$cwd}/error.log\n", $display);
        self::assertSame(1, $this->fetcher()->fetchCount('https://example.com/a'));
        self::assertSame(1, $this->fetcher()->fetchCount('https://example.com/b'));
    }

    public function test_error_log_cannot_be_written(): void
    {
        $source = sys_get_temp_dir() . '/eleanor-import-src-' . bin2hex(random_bytes(4));
        mkdir($source);
        $file = $source . '/one.txt';
        file_put_contents($file, "https://example.com/a\n");
        $this->fetcher()->willThrow('https://example.com/a', new ArchiveException(ArchiveFailure::Transport));

        $locked = sys_get_temp_dir() . '/eleanor-import-locked-' . bin2hex(random_bytes(4));
        mkdir($locked);
        mkdir($locked . '/error.log');
        chmod($locked, 0555);
        $restore = getcwd();
        chdir($locked);
        try {
            $exit = $this->runImport($file, ['--owner' => 'ada@example.com']);
            $display = $this->tester->getDisplay();
            $errorOutput = $this->tester->getErrorOutput();
        } finally {
            if (\is_string($restore)) {
                chdir($restore);
            }
            chmod($locked, 0755);
        }

        self::assertSame(1, $exit);
        self::assertStringContainsString("Written 0\nAlready stored 0\nFailed 1\nSkipped 0\n", $display);
        self::assertStringNotContainsString('For error details see', $display);
        self::assertStringContainsString('The error log could not be written.', $errorOutput);
    }

    public function test_an_unknown_option_exits_1(): void
    {
        $exit = $this->runImport('unused.txt', ['--owner' => 'ada@example.com', '--parallel' => '1']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('--parallel', $this->tester->getErrorOutput());
        self::assertStringNotContainsString('Importing:', $this->tester->getDisplay());
        self::assertStringNotContainsString('Importing:', $this->tester->getErrorOutput());
    }

    /**
     * Reads the fake fetcher from the test container.
     */
    private function fetcher(): FakeArticleFetcher
    {
        $fetcher = static::getContainer()->get(FakeArticleFetcher::class);
        self::assertInstanceOf(FakeArticleFetcher::class, $fetcher);

        return $fetcher;
    }

    /**
     * Runs `archive:import` with `$file` and `$input`, and returns the exit status.
     *
     * @param array<string, string> $input
     */
    private function runImport(string $file, array $input): int
    {
        if (!static::$booted) {
            static::bootKernel();
        }
        $kernel = static::$kernel;
        if (!$kernel instanceof KernelInterface) {
            throw new \RuntimeException('The kernel is not booted.');
        }
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $this->tester = new ApplicationTester($application);

        return $this->tester->run(
            ['command' => 'archive:import', 'file' => $file, ...$input],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );
    }
}
