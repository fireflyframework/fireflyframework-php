<?php

declare(strict_types=1);

namespace Firefly\Testing\Double;

use Firefly\Cqrs\Query\QueryBus;

/** Records every query asked and returns a per-class canned result. */
final class StubQueryBus implements QueryBus
{
    /** @var list<object> */
    public array $asked = [];

    /** @var array<string, mixed> */
    private array $results = [];

    public function willReturn(string $queryClass, mixed $result): self
    {
        $this->results[$queryClass] = $result;

        return $this;
    }

    public function ask(object $query): mixed
    {
        $this->asked[] = $query;

        return $this->results[$query::class] ?? null;
    }
}
