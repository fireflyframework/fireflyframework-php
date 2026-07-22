<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Specification;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Either side may match: `WHERE (left) OR (right)`, each grouped so the OR does not leak into surrounding ANDs.
 *
 * @template TModel of Model
 *
 * @implements Specification<TModel>
 */
final readonly class OrSpecification implements Specification
{
    /**
     * @param  Specification<TModel>  $left
     * @param  Specification<TModel>  $right
     */
    public function __construct(
        private Specification $left,
        private Specification $right,
    ) {}

    public function toBuilder(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $inner) => $this->left->toBuilder($inner))
            ->orWhere(fn (Builder $inner) => $this->right->toBuilder($inner));
    }
}
