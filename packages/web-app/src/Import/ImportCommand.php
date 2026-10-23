<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Import;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yumo\ArtcleReader\UrlGuard\UrlGuard;
use Yumo\Eleanor\Archive\ArchiveServiceFactory;
use Yumo\Eleanor\Archive\Owner;

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
        try {
            $email = $this->requireOwner($input);
            $file = $this->fileArgument($input);
            $stream = $this->streams->open($file);
        } catch (\RuntimeException $failure) {
            $this->errorOutput($output)->writeln($failure->getMessage());

            return Command::FAILURE;
        }

        $processor = new UrlProcessor($this->guard, $this->archiveServices->create(new Owner($email)));
        $output->write($this->summary->heading($file));
        $tally = new ImportTally();
        $sawRow = false;
        foreach ($stream->candidates() as $candidate) {
            $sawRow = true;
            if ($candidate === null) {
                $output->writeln($this->lines->blank());
                $tally->skip();
                continue;
            }
            $result = $processor->process($candidate);
            $output->writeln($this->lines->line($result));
            $tally->record($result);
        }
        if ($sawRow) {
            $output->writeln('');
        }
        if ($tally->failed() === 0 && is_file('error.log')) {
            unlink('error.log');
        }
        $output->write($this->summary->footer($tally, null));

        return Command::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'The file of URLs to import.');
        $this->addOption('owner', null, InputOption::VALUE_REQUIRED, 'The owner email.');
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

    private function errorOutput(OutputInterface $output): OutputInterface
    {
        if ($output instanceof ConsoleOutputInterface) {
            return $output->getErrorOutput();
        }

        return $output;
    }
}
