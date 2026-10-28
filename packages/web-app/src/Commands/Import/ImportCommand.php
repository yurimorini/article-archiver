<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Commands\Import;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yumo\ArtcleReader\UrlGuard\UrlGuard;
use Yumo\Eleanor\Archive\ArchiveServiceFactory;
use Yumo\Eleanor\Archive\Owner;
use Yumo\Eleanor\Import\CandidateStream;
use Yumo\Eleanor\Import\CandidateStreamFactory;
use Yumo\Eleanor\Import\ImportSummaryFormatter;
use Yumo\Eleanor\Import\ImportTally;
use Yumo\Eleanor\Import\ResultLineFormatter;
use Yumo\Eleanor\Import\UrlProcessor;

/**
 * Archives each URL in a file for one owner.
 *
 * `archive:import notes/urls.txt --owner=ada@example.com`
 */
#[AsCommand(
    name: 'archive:import',
    description: 'Archives each URL in a file for the given owner.',
)]
final class ImportCommand extends Command
{
    /**
     * Creates the command from the stream, the formatters, the guard, and the archive factory.
     */
    public function __construct(
        /** Opens the import file as candidates. The command does not name a line reader. */
        private readonly CandidateStreamFactory $streams,
        /** Builds the heading and the totals. */
        private readonly ImportSummaryFormatter $summary,
        /** Builds one result row. */
        private readonly ResultLineFormatter $lines,
        /** Rejects a candidate before the archive write. */
        private readonly UrlGuard $guard,
        /** Builds the archive service of the owner named on the command. */
        private readonly ArchiveServiceFactory $archiveServices,
    ) {
        parent::__construct();
    }

    /**
     * Rejects an empty owner or an unreadable path. A readable file prints the heading and the totals.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $console = new ImportConsole($output, $this->summary, $this->lines);
        try {
            $email = $this->requireOwner($input);
            $file = $this->fileArgument($input);
            $stream = $this->streams->open($file);
        } catch (\RuntimeException $failure) {
            $console->cause($failure->getMessage());

            return Command::FAILURE;
        }

        $tally = $this->walk($file, $stream, $email, $console);

        return $this->finish($tally, $console);
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'The file of URLs to import.');
        $this->addOption('owner', null, InputOption::VALUE_REQUIRED, 'The owner email.');
    }

    /**
     * Prints the heading and one row per candidate.
     */
    private function walk(string $file, CandidateStream $stream, string $email, ImportConsole $console): ImportTally
    {
        $processor = new UrlProcessor($this->guard, $this->archiveServices->create(new Owner($email)));
        $console->heading($file);
        $tally = new ImportTally();
        $sawRow = false;
        foreach ($stream->candidates() as $candidate) {
            $sawRow = true;
            if ($candidate === null) {
                $console->blank();
                $tally->skip();
                continue;
            }
            $result = $processor->process($candidate);
            $console->row($result);
            $tally->record($result);
        }
        if ($sawRow) {
            $console->gap();
        }

        return $tally;
    }

    /**
     * Writes or deletes `error.log`, prints the totals, and returns the exit status.
     */
    private function finish(ImportTally $tally, ImportConsole $console): int
    {
        $errorLogPath = null;
        $exit = Command::SUCCESS;
        if ($tally->errors() !== []) {
            $errorLogPath = $this->writeErrorLog($tally);
            $exit = Command::FAILURE;
            if ($errorLogPath === null) {
                $console->cause('The error log could not be written.');
            }
        } elseif (!$this->deleteErrorLog()) {
            $console->cause('The error log could not be removed.');
            $exit = Command::FAILURE;
        }
        $console->footer($tally, $errorLogPath);

        return $exit;
    }

    /**
     * Returns the owner email from the command.
     *
     * @throws \RuntimeException When the owner email is empty
     */
    private function requireOwner(InputInterface $input): string
    {
        $owner = $input->getOption('owner');
        if (!\is_string($owner) || $owner === '') {
            throw new \RuntimeException('The owner email is empty.');
        }

        return $owner;
    }

    /**
     * @throws \RuntimeException When the file argument is missing
     */
    private function fileArgument(InputInterface $input): string
    {
        $file = $input->getArgument('file');
        if (!\is_string($file)) {
            throw new \RuntimeException('The import file could not be read.');
        }

        return $file;
    }

    /**
     * Writes `error.log` in the working directory and returns its absolute path.
     *
     * Returns null when the file cannot be written. The totals are still printed.
     */
    private function writeErrorLog(ImportTally $tally): ?string
    {
        $body = '';
        foreach ($tally->errors() as $error) {
            $body .= $error['url'] . "\t" . $error['code'] . "\n";
        }

        set_error_handler(static fn (int $severity, string $message): bool => true);
        try {
            $written = file_put_contents('error.log', $body);
        } finally {
            restore_error_handler();
        }
        if ($written === false) {
            return null;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            return null;
        }

        return $cwd . '/error.log';
    }

    /**
     * Deletes a pre-existing `error.log`. Returns false when the file is still there.
     */
    private function deleteErrorLog(): bool
    {
        if (!is_file('error.log')) {
            return true;
        }

        set_error_handler(static fn (int $severity, string $message): bool => true);
        try {
            return unlink('error.log');
        } finally {
            restore_error_handler();
        }
    }
}
