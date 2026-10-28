<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Commands\Import;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Yumo\Eleanor\Import\ImportResult;
use Yumo\Eleanor\Import\ImportSummaryFormatter;
use Yumo\Eleanor\Import\ImportTally;
use Yumo\Eleanor\Import\ResultLineFormatter;

/**
 * Prints import text on the console.
 *
 * It does not count, pad, guard, archive, or touch `error.log`.
 */
#[Exclude]
final class ImportConsole
{
    /**
     * Creates the writer from the command output and the two formatters.
     */
    public function __construct(
        /** The command output. Error text goes to the error output when this is a console output. */
        private readonly OutputInterface $output,
        /** Builds the heading and the totals. */
        private readonly ImportSummaryFormatter $summary,
        /** Builds one result row. */
        private readonly ResultLineFormatter $lines,
    ) {
    }

    /**
     * Prints the heading for `$path`.
     */
    public function heading(string $path): void
    {
        $this->output->write($this->summary->heading($path), false, OutputInterface::OUTPUT_RAW);
    }

    /**
     * Prints the row for a blank candidate.
     */
    public function blank(): void
    {
        $this->output->writeln($this->lines->blank(), OutputInterface::OUTPUT_RAW);
    }

    /**
     * Prints the row for `$result`.
     */
    public function row(ImportResult $result): void
    {
        $this->output->writeln($this->lines->line($result), OutputInterface::OUTPUT_RAW);
    }

    /**
     * Prints the blank line after the last data row.
     */
    public function gap(): void
    {
        $this->output->writeln('', OutputInterface::OUTPUT_RAW);
    }

    /**
     * Prints the four totals and, when `$errorLogPath` is set, the details line.
     */
    public function footer(ImportTally $tally, ?string $errorLogPath): void
    {
        $this->output->write($this->summary->footer($tally, $errorLogPath), false, OutputInterface::OUTPUT_RAW);
    }

    /**
     * Writes `$message` on the error output.
     */
    public function cause(string $message): void
    {
        $this->errorOutput()->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    /**
     * Returns the error output when the command output has one.
     */
    private function errorOutput(): OutputInterface
    {
        if ($this->output instanceof ConsoleOutputInterface) {
            return $this->output->getErrorOutput();
        }

        return $this->output;
    }
}
