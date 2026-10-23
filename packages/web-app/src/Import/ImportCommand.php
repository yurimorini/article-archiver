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
     * Rejects an empty owner or a path that is not a file, then returns 1.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->guard($input);
        } catch (\RuntimeException $failure) {
            $this->errorOutput($output)->writeln($failure->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'The file of URLs to import.');
        $this->addOption('owner', null, InputOption::VALUE_REQUIRED, 'The owner email.');
    }

    /**
     * Rejects an empty owner or a path that is not a file.
     *
     * @throws \RuntimeException When the owner email is empty or the path is not a file
     */
    private function guard(InputInterface $input): void
    {
        $owner = $input->getOption('owner');
        if (!\is_string($owner) || $owner === '') {
            throw new \RuntimeException('The owner email is empty.');
        }

        $file = $input->getArgument('file');
        if (!\is_string($file) || !is_file($file)) {
            throw new \RuntimeException('The import file could not be read.');
        }
    }

    private function errorOutput(OutputInterface $output): OutputInterface
    {
        if ($output instanceof ConsoleOutputInterface) {
            return $output->getErrorOutput();
        }

        return $output;
    }
}
