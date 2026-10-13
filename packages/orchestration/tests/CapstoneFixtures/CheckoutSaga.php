<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\CapstoneFixtures;

use Firefly\Container\Attributes\Service;
use Firefly\Orchestration\Core\Argument\FromStep;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Saga\Attributes\OnSagaComplete;
use Firefly\Orchestration\Saga\Attributes\OnSagaError;
use Firefly\Orchestration\Saga\Attributes\Saga;
use Firefly\Orchestration\Saga\Attributes\SagaStep;
use Firefly\Orchestration\Saga\Attributes\ScheduledSaga;
use Firefly\Orchestration\Saga\Attributes\StepEvent;
use Firefly\Orchestration\Saga\Engine\SagaResult;
use RuntimeException;
use Throwable;

#[Service]
#[Saga(name: 'checkout', triggerEventType: 'orders.placed')]
#[ScheduledSaga(cron: '0 2 * * *', input: '{"orderId": 1, "amount": 5}', description: 'nightly checkout')]
final class CheckoutSaga
{
    public function __construct(private readonly Ledger $ledger) {}

    /** @return array{reservation: string} */
    #[SagaStep(id: 'reserve', compensate: 'release')]
    public function reserve(#[Input('orderId')] int $orderId): array
    {
        $this->ledger->record("reserve {$orderId}");

        return ['reservation' => "r-{$orderId}"];
    }

    /** @param array{reservation: string} $reservation */
    public function release(array $reservation): void
    {
        $this->ledger->record("release {$reservation['reservation']}");
    }

    /** @param array{reservation: string} $reservation */
    #[SagaStep(id: 'charge', dependsOn: ['reserve'], compensate: 'refund', retry: 1, backoffMs: 1)]
    #[StepEvent(topic: 'payments', type: 'payment.captured', key: 'orderId')]
    public function charge(#[Input('amount')] int $amount, #[FromStep('reserve')] array $reservation): string
    {
        if ($amount > 100) {
            throw new RuntimeException("amount {$amount} exceeds the limit");
        }
        $this->ledger->record("charge {$amount} for {$reservation['reservation']}");

        return 'receipt-1';
    }

    public function refund(string $receipt): void
    {
        $this->ledger->record("refund {$receipt}");
    }

    #[OnSagaComplete]
    public function completed(SagaResult $result): void
    {
        $this->ledger->record("completed {$result->sagaName}");
    }

    #[OnSagaError]
    public function failed(Throwable $error): void
    {
        $this->ledger->record("failed: {$error->getMessage()}");
    }
}
