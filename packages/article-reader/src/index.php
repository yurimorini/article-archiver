<?php

declare(strict_types=1);

use Yumo\LogRead\Orchestrator\ArticleFetchCli;
use Yumo\LogRead\Orchestrator\OrchestratorFactory;

require __DIR__ . '/../vendor/autoload.php';

/** @var list<string> $argv */
exit(new ArticleFetchCli(OrchestratorFactory::create())->run($argv, STDOUT, STDERR));
