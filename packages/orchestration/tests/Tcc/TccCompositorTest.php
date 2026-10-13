<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\DefinitionNotFoundException;
use Firefly\Orchestration\Core\Exception\PatternDisabledException;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Tcc\Builder\TccBuilder;
use Firefly\Orchestration\Tcc\Composition\TccCompositionBuilder;
use Firefly\Orchestration\Tests\Support\TestEngines;

/** @param list<string> $log */
function orchestrationTccPair(TestEngines $engines, array &$log): void
{
    foreach (['hold-funds' => false, 'book-seat' => true] as $name => $failsWhenAsked) {
        $engines->tccRegistry->register(TccBuilder::tcc($name)->retry(false)
            ->participant('main')
            ->tryWith(static function (array $input) use ($name, $failsWhenAsked, &$log): array {
                $log[] = "try {$name}";
                if ($failsWhenAsked && ($input['fail'] ?? false) === true) {
                    throw new RuntimeException("{$name} unavailable");
                }

                return ['ref' => "{$name}-ref", 'holdRef' => is_string($input['holdRef'] ?? null) ? $input['holdRef'] : null];
            })
            ->confirmWith(static function () use ($name, &$log): void {
                $log[] = "confirm {$name}";
            })
            ->cancelWith(static function () use ($name, &$log): void {
                $log[] = "cancel {$name}";
            })
            ->add()->build());
    }
}

it('confirms members in dependency order, flowing try results forward', function (): void {
    $engines = TestEngines::create();
    $log = [];
    orchestrationTccPair($engines, $log);
    $composition = TccCompositionBuilder::composition('trip')
        ->tcc('funds')->tccName('hold-funds')->add()
        ->tcc('seat')->tccName('book-seat')->dependsOn('funds')->add()
        ->dataFlow('funds', 'ref', 'seat', 'holdRef')
        ->build();

    $result = $engines->tccCompositor->compose($composition);

    expect($result->success)->toBeTrue()
        ->and($result->results['seat']->tryResultOf('main'))->toBe(['ref' => 'book-seat-ref', 'holdRef' => 'hold-funds-ref'])
        ->and($log)->toBe(['try hold-funds', 'confirm hold-funds', 'try book-seat', 'confirm book-seat']);
});

it('cancels the confirmed members when a later member fails', function (): void {
    $engines = TestEngines::create();
    $log = [];
    orchestrationTccPair($engines, $log);
    $composition = TccCompositionBuilder::composition('trip')
        ->tcc('funds')->tccName('hold-funds')->add()
        ->tcc('seat')->tccName('book-seat')->dependsOn('funds')->add()
        ->build();

    $result = $engines->tccCompositor->compose($composition, ['fail' => true]);

    expect($result->success)->toBeFalse()
        ->and($result->compensated)->toBe(['funds'])
        ->and($log)->toBe(['try hold-funds', 'confirm hold-funds', 'try book-seat', 'cancel hold-funds'])
        ->and($engines->persistence->findById($result->results['funds']->correlationId)?->status)->toBe(ExecutionStatus::Canceled);
});

it('refuses compositions while tcc.composition is disabled', function (): void {
    $engines = TestEngines::create(['tcc' => ['composition' => ['enabled' => false]]]);

    $engines->tccCompositor->compose(TccCompositionBuilder::composition('x')->tcc('a')->tccName('a')->add()->build());
})->throws(PatternDisabledException::class, 'firefly.orchestration.tcc.composition.enabled');

it('rolls back the confirmed members when a required member cannot start at all', function (): void {
    $engines = TestEngines::create();
    $log = [];
    orchestrationTccPair($engines, $log);
    $composition = TccCompositionBuilder::composition('trip')
        ->tcc('funds')->tccName('hold-funds')->add()
        ->tcc('hotel')->tccName('book-hotel')->dependsOn('funds')->add()
        ->build();

    $result = $engines->tccCompositor->compose($composition);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBeInstanceOf(DefinitionNotFoundException::class)
        ->and($result->compensated)->toBe(['funds'])
        ->and($log)->toBe(['try hold-funds', 'confirm hold-funds', 'cancel hold-funds']);
});

it('tolerates an optional member that fails or cannot start, and skips its dependents', function (): void {
    $engines = TestEngines::create();
    $log = [];
    orchestrationTccPair($engines, $log);
    $composition = TccCompositionBuilder::composition('trip')
        ->tcc('funds')->tccName('hold-funds')->add()
        ->tcc('seat')->tccName('book-seat')->optional()->add()
        ->tcc('hotel')->tccName('book-hotel')->optional()->add()
        ->tcc('meal')->tccName('hold-funds')->dependsOn('seat')->add()
        ->build();

    $result = $engines->tccCompositor->compose($composition, ['fail' => true]);

    expect($result->success)->toBeTrue()
        ->and(array_keys($result->optionalFailures))->toBe(['seat', 'hotel'])
        ->and($result->optionalFailures['seat']->getMessage())->toBe('book-seat unavailable')
        ->and($result->skipped)->toBe(['meal'])
        ->and($log)->toBe(['try hold-funds', 'confirm hold-funds', 'try book-seat']);
});

it('reports a confirmed member whose cancel failed instead of listing it as compensated', function (): void {
    $engines = TestEngines::create();
    $log = [];
    $engines->tccRegistry->register(TccBuilder::tcc('stubborn')->retry(false)
        ->participant('main')
        ->tryWith(static fn (): string => 'held')
        ->confirmWith(static fn (): null => null)
        ->cancelWith(static function (): void {
            throw new RuntimeException('ledger offline');
        })
        ->add()->build());
    orchestrationTccPair($engines, $log);
    $composition = TccCompositionBuilder::composition('trip')
        ->tcc('stubborn')->add()
        ->tcc('funds')->tccName('hold-funds')->dependsOn('stubborn')->add()
        ->tcc('seat')->tccName('book-seat')->dependsOn('funds')->add()
        ->build();

    $result = $engines->tccCompositor->compose($composition, ['fail' => true]);

    expect($result->success)->toBeFalse()
        ->and($result->compensated)->toBe(['funds'])
        ->and(array_keys($result->compensationFailures))->toBe(['stubborn'])
        ->and($result->compensationFailures['stubborn']->getMessage())->toBe('TCC composition member [stubborn] left [main] uncanceled: cancel failed: [main] ledger offline')
        ->and($engines->persistence->findById($result->results['stubborn']->correlationId)?->status)->toBe(ExecutionStatus::Failed)
        ->and($engines->events->hooks())->toContain('onStepCompensationFailed')
        ->and($result->toArray()['compensationFailures'])->toBe(['stubborn' => $result->compensationFailures['stubborn']->getMessage()]);
});

/**
 * One single-participant TCC per name, logging "cancel <name>". `$cancelFailures` says how many times a member's
 * cancel fails before it succeeds (PHP_INT_MAX: always); the TCC named `fail` refuses its try.
 *
 * @param  list<string>  $names
 * @param  list<string>  $log
 * @param  array<string, int>  $cancelFailures
 */
function orchestrationTccMembers(TestEngines $engines, array $names, array &$log, array $cancelFailures = []): void
{
    // One array shared by reference: a per-iteration `$failures` captured by reference would be one variable
    // that every later iteration overwrites.
    $remaining = $cancelFailures;
    foreach ([...$names, 'fail'] as $name) {
        $engines->tccRegistry->register(TccBuilder::tcc($name)->retry(false)
            ->participant('main')
            ->tryWith(static function () use ($name): string {
                if ($name === 'fail') {
                    throw new RuntimeException('fail refused');
                }

                return $name;
            })
            ->confirmWith(static fn (): null => null)
            ->cancelWith(static function () use ($name, &$log, &$remaining): void {
                $log[] = "cancel {$name}";
                if (($remaining[$name] ?? 0) > 0) {
                    $remaining[$name]--;

                    throw new RuntimeException("{$name} cancel failed");
                }
            })
            ->add()->build());
    }
}

it('cancels the confirmed members layer by layer in reverse under grouped-parallel', function (): void {
    $engines = TestEngines::create(['tcc' => ['composition' => ['compensation-policy' => 'grouped-parallel']]]);
    $log = [];
    orchestrationTccMembers($engines, ['a', 'b', 'c'], $log);
    // Completion order is a, b, c; reverse completion would cancel c, b, a. The layers are [a, b] then [c].
    $composition = TccCompositionBuilder::composition('trip')
        ->tcc('a')->add()
        ->tcc('b')->add()
        ->tcc('c')->dependsOn('a')->add()
        ->tcc('fail')->dependsOn('c')->add()
        ->build();

    $result = $engines->tccCompositor->compose($composition);

    expect($result->success)->toBeFalse()
        ->and($result->compensated)->toBe(['c', 'a', 'b'])
        ->and($log)->toBe(['cancel c', 'cancel a', 'cancel b']);
});

it('compensates a member again after a failed cancel under retry-with-backoff', function (): void {
    $engines = TestEngines::create(
        ['tcc' => ['composition' => ['compensation-policy' => 'retry-with-backoff']]],
        resilience: ['retry' => ['orchestration-tcc-composition-trip' => ['max-attempts' => 3]]],
    );
    $log = [];
    orchestrationTccMembers($engines, ['a', 'flaky'], $log, ['flaky' => 2]);
    $composition = TccCompositionBuilder::composition('trip')
        ->tcc('a')->add()
        ->tcc('flaky')->dependsOn('a')->add()
        ->tcc('fail')->dependsOn('flaky')->add()
        ->build();

    $result = $engines->tccCompositor->compose($composition);

    expect($result->success)->toBeFalse()
        ->and($result->compensated)->toBe(['flaky', 'a'])
        ->and($result->compensationFailures)->toBe([])
        ->and($log)->toBe(['cancel flaky', 'cancel flaky', 'cancel flaky', 'cancel a'])
        ->and($engines->persistence->findById($result->results['flaky']->correlationId)?->status)->toBe(ExecutionStatus::Canceled);
});

it('does not compensate a member twice under strict-sequential', function (): void {
    $engines = TestEngines::create(resilience: ['retry' => ['orchestration-tcc-composition-trip' => ['max-attempts' => 3]]]);
    $log = [];
    orchestrationTccMembers($engines, ['a', 'flaky'], $log, ['flaky' => 1]);
    $composition = TccCompositionBuilder::composition('trip')
        ->tcc('a')->add()
        ->tcc('flaky')->dependsOn('a')->add()
        ->tcc('fail')->dependsOn('flaky')->add()
        ->build();

    $result = $engines->tccCompositor->compose($composition);

    expect($result->compensated)->toBe(['a'])
        ->and(array_keys($result->compensationFailures))->toBe(['flaky'])
        ->and($log)->toBe(['cancel flaky', 'cancel a']);
});

it('stops the rollback once the composition breaker opens and records the members it left', function (): void {
    $engines = TestEngines::create(
        ['tcc' => ['composition' => ['compensation-policy' => 'circuit-breaker']]],
        resilience: ['circuit-breaker' => ['orchestration-tcc-composition-trip' => ['failure-threshold' => 1]]],
    );
    $log = [];
    orchestrationTccMembers($engines, ['a', 'b', 'c'], $log, ['c' => PHP_INT_MAX]);
    $composition = TccCompositionBuilder::composition('trip')
        ->tcc('a')->add()
        ->tcc('b')->dependsOn('a')->add()
        ->tcc('c')->dependsOn('b')->add()
        ->tcc('fail')->dependsOn('c')->add()
        ->build();

    $result = $engines->tccCompositor->compose($composition);

    expect($result->success)->toBeFalse()
        ->and($result->compensated)->toBe([])
        ->and(array_keys($result->compensationFailures))->toBe(['c', 'b', 'a'])
        ->and($result->compensationFailures['b']->getMessage())->toBe('TCC composition [trip] did not cancel member [b]: its compensation circuit opened at member [c].')
        ->and($log)->toBe(['cancel c'])
        ->and($engines->persistence->findById($result->results['b']->correlationId)?->status)->toBe(ExecutionStatus::Confirmed)
        ->and($engines->persistence->findById($result->results['a']->correlationId)?->status)->toBe(ExecutionStatus::Confirmed)
        ->and($engines->events->count('onStepCompensationFailed'))->toBe(3);
});

it('attempts every member past a failed cancel under best-effort', function (): void {
    $engines = TestEngines::create(['tcc' => ['composition' => ['compensation-policy' => 'best-effort']]]);
    $log = [];
    orchestrationTccMembers($engines, ['a', 'b', 'c'], $log, ['c' => PHP_INT_MAX]);
    $composition = TccCompositionBuilder::composition('trip')
        ->tcc('a')->add()
        ->tcc('b')->dependsOn('a')->add()
        ->tcc('c')->dependsOn('b')->add()
        ->tcc('fail')->dependsOn('c')->add()
        ->build();

    $result = $engines->tccCompositor->compose($composition);

    expect($result->compensated)->toBe(['b', 'a'])
        ->and(array_keys($result->compensationFailures))->toBe(['c'])
        ->and($log)->toBe(['cancel c', 'cancel b', 'cancel a']);
});
