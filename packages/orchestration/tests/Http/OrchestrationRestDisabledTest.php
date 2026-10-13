<?php

declare(strict_types=1);

use Firefly\Orchestration\Tests\Support\OrchestrationCapstoneTestCase;

uses(OrchestrationCapstoneTestCase::class);

it('mounts no route while firefly.orchestration.rest.enabled is false', function (): void {
    /** @var OrchestrationCapstoneTestCase $this */
    $this->postJson('/orchestration/sagas/checkout', ['input' => ['orderId' => 1, 'amount' => 5]])->assertNotFound();

    expect(collect($this->app()->make('router')->getRoutes()->getRoutes())->map->getName()->filter(
        static fn (?string $name): bool => is_string($name) && str_starts_with($name, 'firefly.orchestration.'),
    )->all())->toBe([]);
});
