<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

/** The orchestration capstone with the REST surface mounted under `/ops/orchestration`. */
abstract class RestCapstoneTestCase extends OrchestrationCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.orchestration.rest.enabled' => true,
            'firefly.orchestration.rest.path' => 'ops/orchestration',
        ];
    }
}
