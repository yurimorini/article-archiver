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
     * Creates the command from the stream factory and the summary formatter.
     */
    public function __construct(
        /** Opens the import file as candidates. The command does not name a line reader. */
        private readonly CandidateStreamFactory $streams,
        /** Builds the heading and the totals. */
        private readonly ImportSummaryFormatter $summary,
    ) {
        parent::__construct();
    }

    /**
     * Rejects an empty owner or an unreadable path. A readable file prints the heading and the totals.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->requireOwner($input);
            $file = $this->fileArgument($input);
            $stream = $this->streams->open($file);
        } catch (\RuntimeException $failure) {
            $this->errorOutput($output)->writeln($failure->getMessage());

            return Command::FAILURE;
        }

        $output->write($this->summary->heading($file));
        $tally = new ImportTally();
        foreach ($stream->candidates() as $candidate) {
            if ($candidate === null) {
                $tally->skip();
            }
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
     * @throws \RuntimeException When the owner email is empty
     */
    private function requireOwner(InputInterface $input): void
    {
        $owner = $input->getOption('owner');
        if (!\is_string($owner) || $owner === '') {
            throw new \RuntimeException('The owner email is empty.');
        }
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
