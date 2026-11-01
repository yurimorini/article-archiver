<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$serverArgv = $_SERVER['argv'] ?? null;
$argv = [];
if (is_array($serverArgv)) {
    foreach ($serverArgv as $argument) {
        if (is_string($argument)) {
            $argv[] = $argument;
        }
    }
}

exit(\Yumo\Eleanor\Probe\ProbeRun::main(array_slice($argv, 1)));
