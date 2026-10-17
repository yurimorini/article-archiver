<?php

declare(strict_types=1);

use Yumo\ArtcleReader\Orchestrator\ArticleFetchCli;
use Yumo\ArtcleReader\Orchestrator\OrchestratorFactory;

require __DIR__ . '/../vendor/autoload.php';

/** @var list<string> $argv */
exit(new ArticleFetchCli(OrchestratorFactory::create())->run($argv, STDOUT, STDERR));
