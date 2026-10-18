<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tools;

use OpenApi\Generator;

/**
 * Builds the OpenAPI document of the HTTP API from the attributes in `src/Api`.
 */
final class OpenApiDocument
{
    /**
     * Returns the OpenAPI 3.1.0 document as YAML.
     */
    public static function generate(): string
    {
        $openApi = new Generator()
            ->setVersion('3.1.0')
            ->generate([self::packagePath() . '/src/Api']);

        if ($openApi === null) {
            throw new \RuntimeException('swagger-php produced no OpenAPI document.');
        }

        return $openApi->toYaml();
    }

    /**
     * Returns the absolute path of the committed document.
     */
    public static function committedPath(): string
    {
        return self::packagePath() . '/doc/openapi.yaml';
    }

    private static function packagePath(): string
    {
        return \dirname(__DIR__);
    }
}
