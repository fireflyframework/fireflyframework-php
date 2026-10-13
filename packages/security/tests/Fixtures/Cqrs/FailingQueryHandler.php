<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Cqrs;

final class FailingQueryHandler
{
    public function handle(FailingQuery $query): never
    {
        throw Failures::make($query->failure);
    }
}
