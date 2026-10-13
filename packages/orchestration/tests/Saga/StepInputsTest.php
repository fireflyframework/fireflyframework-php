<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Saga\Engine\StepInputs;

it('prefers an explicit input, then a memoized resolver, then the global input', function (): void {
    $calls = 0;
    $context = ExecutionContext::start(ExecutionPattern::Saga, 's', [], [], 'c', new DateTimeImmutable);
    $context->putResult('reserve', ['hold' => 'h-1']);
    $inputs = StepInputs::of(['orderId' => 7])
        ->forStep('reserve', ['sku' => 'A-1'])
        ->forStepResolver('charge', static function (ExecutionContext $ctx) use (&$calls): mixed {
            $calls++;

            return $ctx->result('reserve');
        });

    expect($inputs->resolveFor('reserve', $context))->toBe(['sku' => 'A-1'])
        ->and($inputs->resolveFor('charge', $context))->toBe(['hold' => 'h-1'])
        ->and($inputs->resolveFor('charge', $context))->toBe(['hold' => 'h-1'])
        ->and($calls)->toBe(1)
        ->and($inputs->resolveFor('notify', $context))->toBe(['orderId' => 7])
        ->and($inputs->global())->toBe(['orderId' => 7])
        ->and($inputs->explicit())->toBe(['reserve' => ['sku' => 'A-1']]);
});
