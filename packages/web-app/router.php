<?php

declare(strict_types=1);

$file = ($_SERVER['DOCUMENT_ROOT'] ?? '') . ($_SERVER['SCRIPT_NAME'] ?? '');
if (is_file($file)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';

require $_SERVER['SCRIPT_FILENAME'];
