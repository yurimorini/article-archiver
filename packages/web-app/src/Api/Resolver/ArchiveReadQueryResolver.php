<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Yumo\Eleanor\Api\Dto\ArchiveReadQuery;
use Yumo\Eleanor\Archive\InvalidArchiveQuery;

/**
 * Reads `includeContent` into a read query for a controller argument of that type.
 *
 * A missing key stays null. Any other query key is ignored. An array is not a legal value.
 */
final class ArchiveReadQueryResolver implements ValueResolverInterface
{
    /**
     * Returns the content flag for an `ArchiveReadQuery` argument, and nothing for any other argument.
     *
     * @return list<ArchiveReadQuery>
     *
     * @throws InvalidArchiveQuery When `includeContent` is an array or not a string
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        if ($argument->getType() !== ArchiveReadQuery::class) {
            return [];
        }

        return [new ArchiveReadQuery($this->queryString($request, 'includeContent'))];
    }

    /**
     * Returns the string for `$key`, or null when the key is absent.
     *
     * @throws InvalidArchiveQuery When the value is an array or not a string
     */
    private function queryString(Request $request, string $key): ?string
    {
        $query = $request->query->all();

        if (!array_key_exists($key, $query)) {
            return null;
        }

        $value = $query[$key];

        if (!is_string($value)) {
            throw new InvalidArchiveQuery();
        }

        return $value;
    }
}
