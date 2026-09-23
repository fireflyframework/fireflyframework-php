<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;

/**
 * The JSON envelopes Laravel's three paginators write — `->paginate()`, `->simplePaginate()`,
 * `->cursorPaginate()` — around the elements of a page.
 *
 * WHY THEY ARE STATED HERE rather than read. Each envelope is the body of the paginator's toArray(), and those
 * methods say nothing more than `@return array`; the members, their order and their nullability exist only in
 * the code. So they are written down once, as facts of the framework — which is how springdoc knows Spring
 * Data's Page — against Laravel's own source: a `from`/`to` is null on an empty page, every URL but the first
 * and last page's is null past an edge, `path` is nullable, and the `...` separator in `links` carries no
 * `page`. Reflecting the class instead published LengthAwarePaginator's one public property, `onEachSide`.
 *
 * Keyed by the CONTRACT, so a `LengthAwarePaginator` return and a `Contracts\Pagination\LengthAwarePaginator`
 * return share one component instead of minting two identical ones.
 */
final class PaginatorSchema
{
    /**
     * The paginator contract $class serialises by, or null when it is not a paginator. Length-aware first: it
     * extends the simple contract, and its envelope is the larger one.
     *
     * @return class-string|null
     */
    public static function contract(string $class): ?string
    {
        foreach ([LengthAwarePaginator::class, CursorPaginator::class, Paginator::class] as $contract) {
            if (is_a($class, $contract, true)) {
                return $contract;
            }
        }

        return null;
    }

    /**
     * @param  class-string  $contract  one of the three contract() returns
     * @param  array<string, mixed>  $element  the schema of one element of `data`; empty when unknown
     * @return array<string, mixed>
     */
    public static function envelope(string $contract, array $element): array
    {
        $int = ['type' => 'integer'];
        $string = ['type' => 'string'];
        $nullableInt = ['type' => ['integer', 'null']];
        $nullableString = ['type' => ['string', 'null']];
        $data = $element === [] ? ['type' => 'array'] : ['type' => 'array', 'items' => $element];

        $properties = match ($contract) {
            LengthAwarePaginator::class => [
                'current_page' => $int,
                'data' => $data,
                'first_page_url' => $string,
                'from' => $nullableInt,
                'last_page' => $int,
                'last_page_url' => $string,
                'links' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['url' => $nullableString, 'label' => $string, 'page' => $nullableInt, 'active' => ['type' => 'boolean']],
                    'required' => ['url', 'label', 'active'],
                ]],
                'next_page_url' => $nullableString,
                'path' => $nullableString,
                'per_page' => $int,
                'prev_page_url' => $nullableString,
                'to' => $nullableInt,
                'total' => $int,
            ],
            CursorPaginator::class => [
                'data' => $data,
                'path' => $nullableString,
                'per_page' => $int,
                'next_cursor' => $nullableString,
                'next_page_url' => $nullableString,
                'prev_cursor' => $nullableString,
                'prev_page_url' => $nullableString,
            ],
            default => [
                'current_page' => $int,
                'current_page_url' => $string,
                'data' => $data,
                'first_page_url' => $string,
                'from' => $nullableInt,
                'next_page_url' => $nullableString,
                'path' => $nullableString,
                'per_page' => $int,
                'prev_page_url' => $nullableString,
                'to' => $nullableInt,
            ],
        };

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
        ];
    }

    public static function description(string $contract): string
    {
        return match ($contract) {
            LengthAwarePaginator::class => 'One page of results in Laravel\'s length-aware pagination envelope: the elements under `data`, with the page position, the total and the page links.',
            CursorPaginator::class => 'One page of results in Laravel\'s cursor pagination envelope: the elements under `data`, with the cursors that fetch the pages either side.',
            default => 'One page of results in Laravel\'s simple pagination envelope: the elements under `data`, with the page position but no total.',
        };
    }
}
