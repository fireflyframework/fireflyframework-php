<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\Cache\Fixtures;

use Firefly\Cqrs\Query\Query;

/** Scalar properties whose types JSON blurs: a float written as 5 and a nullable int sent as null. */
final readonly class LimitedBalance implements Query
{
    public function __construct(public string $account, public float $limit, public ?int $page) {}
}
