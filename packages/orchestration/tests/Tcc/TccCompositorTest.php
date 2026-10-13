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
