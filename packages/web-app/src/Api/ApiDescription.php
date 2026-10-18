<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api;

use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The Eleanor HTTP API: archive the article of a web page.
 */
#[Exclude]
#[OA\Info(title: 'Eleanor API', version: '0.1.0')]
final class ApiDescription
{
}
