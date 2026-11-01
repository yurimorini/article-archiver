<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Yumo\Eleanor\Api\Dto\ListArchivesQuery;
use Yumo\Eleanor\Archive\InvalidArchiveQuery;

/**
 * Reads `sort`, `dir`, `page`, and `size` into a list query for a controller argument of that type.
 *
 * A missing key stays null. Any other query key is ignored. An array is not a legal value.
 */
final class ListArchivesQueryResolver implements ValueResolverInterface
{
    /**
     * Returns the four query strings for a `ListArchivesQuery` argument, and nothing for any other argument.
     *
     * @return list<ListArchivesQuery>
     *
     * @throws InvalidArchiveQuery When `sort`, `dir`, `page`, or `size` is an array
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        if ($argument->getType() !== ListArchivesQuery::class) {
            return [];
        }

        return [new ListArchivesQuery(
            $this->queryString($request, 'sort'),
            $this->queryString($request, 'dir'),
            $this->queryString($request, 'page'),
            $this->queryString($request, 'size'),
        )];
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
