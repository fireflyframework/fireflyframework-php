<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\ScannerFixtures\Good;

use Firefly\Orchestration\Core\Argument\FromStep;
use Firefly\Orchestration\Core\Argument\Header;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Core\Argument\Required;
use Firefly\Orchestration\Core\Argument\SetVariable;
use Firefly\Orchestration\Core\Argument\Variable;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Saga\Attributes\OnSagaComplete;
use Firefly\Orchestration\Saga\Attributes\OnSagaError;
use Firefly\Orchestration\Saga\Attributes\Saga;
use Firefly\Orchestration\Saga\Attributes\SagaStep;
use Firefly\Orchestration\Saga\Attributes\ScheduledSaga;
use Firefly\Orchestration\Saga\Attributes\StepEvent;
use Firefly\Orchestration\Saga\Engine\SagaResult;
use RuntimeException;
use Throwable;

#[Saga(name: 'order', triggerEventType: 'orders.placed')]
#[ScheduledSaga(cron: '0 3 * * *', input: '{"source":"nightly"}', description: 'nightly reconciliation')]
final class OrderSaga
{
    /** @return array{hold: string} */
    #[SagaStep(id: 'reserve', compensate: 'release', retry: 2, backoffMs: 5, timeoutMs: 1000, idempotencyKey: 'reserve-{input.orderId}')]
    public function reserve(#[Input('orderId')] int $orderId, ExecutionContext $ctx): array
    {
        return ['hold' => "h-{$orderId}"];
    }

    /**
     * @param  array<string, mixed>  $hold
     * @param  array<string, mixed>  $input
     */
    public function release(array $hold, #[Input] array $input): void {}

    /** @param array<string, mixed> $hold */
    #[SagaStep(id: 'charge', dependsOn: ['reserve'], compensate: 'refund', compensationCritical: true)]
    #[StepEvent(topic: 'payments', type: 'payment.captured', key: 'orderId')]
    #[SetVariable('receipt')]
    public function charge(#[FromStep('reserve')] array $hold, #[Header('tenant')] ?string $tenant, #[Required] #[Variable('limit')] mixed $limit = null): string
    {
        return 'paid';
    }

    public function refund(string $receipt): void {}

    #[OnSagaComplete(priority: 5)]
    public function done(SagaResult $result, ExecutionContext $ctx): void {}

    #[OnSagaError(errorTypes: [RuntimeException::class])]
    public function failed(Throwable $error): void {}
}
