<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Event\CompositeOrchestrationEvents;
use Firefly\Orchestration\Event\OrchestrationEventsAdapter;
use Firefly\Orchestration\Tests\Support\RecordingLogger;
use Firefly\Orchestration\Tests\Support\RecordingOrchestrationEvents;

it('fans every hook out, isolates a throwing listener and discovers application listeners once', function (): void {
    $first = new RecordingOrchestrationEvents;
    $discovered = new RecordingOrchestrationEvents;
    $broken = new class extends OrchestrationEventsAdapter
    {
        public function onStart(string $name, string $correlationId, ExecutionPattern $pattern): void
        {
            throw new RuntimeException('listener down');
        }
    };
    $logger = new RecordingLogger;
    $lookups = 0;
    $composite = null;
    $composite = new CompositeOrchestrationEvents([$first, $broken], static function () use (&$lookups, &$composite, $discovered): array {
        $lookups++;

        return [$discovered, $composite, new stdClass];
    }, $logger);

    $composite->onStart('order', 'c-1', ExecutionPattern::Saga);
    $composite->onStepCompensated('order', 'c-1', 'reserve');

    expect($first->hooks())->toBe(['onStart', 'onStepCompensated'])
        ->and($discovered->hooks())->toBe(['onStart', 'onStepCompensated'])
        ->and($lookups)->toBe(1)
        ->and($composite->listeners())->toHaveCount(3)
        ->and($logger->messages('warning')[0] ?? '')->toContain('listener down');
});
