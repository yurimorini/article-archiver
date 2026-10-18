<?php

declare(strict_types=1);

use Yumo\Eleanor\Tools\OpenApiDocument;

require \dirname(__DIR__) . '/vendor/autoload.php';

file_put_contents(OpenApiDocument::committedPath(), OpenApiDocument::generate());
