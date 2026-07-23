<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Query;

/**
 * The query dispatch PORT: ask a query through the read pipeline (correlate -> validate -> authorize -> cache-get ->
 * resolve+invoke handler -> cache-put -> metrics). `ask` is the read-side verb (chosen over pyfly's `query` to avoid
 * colliding with Eloquent's query()). DefaultQueryBus is the shipped implementation.
 */
interface QueryBus
{
    public function ask(object $query): mixed;
}
