<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Specification;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A composable query predicate over an Eloquent Builder. `toBuilder()` applies this specification's constraints
 * and returns the Builder for chaining. Compose with the combinator classes or the Specifications factory.
 *
 * @template TModel of Model
 */
interface Specification
{
    /**
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function toBuilder(Builder $query): Builder;
}
