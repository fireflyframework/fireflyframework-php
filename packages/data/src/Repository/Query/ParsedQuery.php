<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Query;

/**
 * The parsed shape of a derived-query method name. Purely structural — it holds no reflection and no Builder;
 * Task 13's dispatch consumes it to drive the Eloquent Builder and bind args to predicates in order.
 *
 * @phpstan-type PredicateRow array{field: string, op: string, ignoreCase: bool, boolLiteral: bool|null}
 * @phpstan-type OrderRow array{field: string, dir: 'asc'|'desc'}
 * @phpstan-type ParsedQueryData array{
 *     prefix: 'find'|'count'|'exists'|'delete',
 *     top: int|null,
 *     distinct: bool,
 *     predicates: list<PredicateRow>,
 *     connectors: list<'And'|'Or'>,
 *     orders: list<OrderRow>,
 * }
 */
final readonly class ParsedQuery
{
    /**
     * @param  'find'|'count'|'exists'|'delete'  $prefix
     * @param  list<Predicate>  $predicates
     * @param  list<'And'|'Or'>  $connectors
     * @param  list<OrderClause>  $orders
     */
    public function __construct(
        public string $prefix,
        public ?int $top,
        public bool $distinct,
        public array $predicates,
        public array $connectors,
        public array $orders,
    ) {}

    /**
     * @return ParsedQueryData
     */
    public function toArray(): array
    {
        return [
            'prefix' => $this->prefix,
            'top' => $this->top,
            'distinct' => $this->distinct,
            'predicates' => array_map(static fn (Predicate $p): array => $p->toArray(), $this->predicates),
            'connectors' => $this->connectors,
            'orders' => array_map(static fn (OrderClause $o): array => $o->toArray(), $this->orders),
        ];
    }
}
