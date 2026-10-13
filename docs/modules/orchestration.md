# Orchestration

`firefly/orchestration` coordinates work that spans more than one transaction. It ships three patterns, shaped
after the Java `fireflyframework-orchestration` engine:

- **Sagas** run forward steps in dependency order and, when one fails, undo the completed ones with their
  compensations under one of five compensation policies.
- **TCC** (try/confirm/cancel) reserves with every participant first, then confirms all of them or cancels
  the ones that tried.
- **Durable workflows** run a step graph that can wait for signals and timers, start child workflows, answer
  queries, suspend and resume, and survive a process that dies between two steps.

Every step boundary writes an `ExecutionState` checkpoint through the `ExecutionPersistenceProvider` port, so
a recovery sweep can resume or compensate whatever a crashed process left behind. Failures land in a
dead-letter queue. Lifecycle events publish through `firefly/eda`, and an EDA event can start a definition.

## Declaring a saga

A saga is a bean with `#[Saga]` and one `#[SagaStep]` per forward step. `compensate` names the method that
undoes the step; `dependsOn` orders the steps into topology layers. Parameters are injected by attribute:
`#[Input]` reads the execution input, `#[FromStep]` the result of an earlier step, and an `ExecutionContext`
parameter receives the live context.

<!-- illustrative: a saga an application declares -->
```php
use Firefly\Container\Attributes\Service;
use Firefly\Orchestration\Core\Argument\FromStep;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Saga\Attributes\OnSagaError;
use Firefly\Orchestration\Saga\Attributes\Saga;
use Firefly\Orchestration\Saga\Attributes\SagaStep;
use Firefly\Orchestration\Saga\Attributes\StepEvent;

#[Service]
#[Saga(name: 'checkout', triggerEventType: 'orders.placed')]
final class CheckoutSaga
{
    #[SagaStep(id: 'reserve', compensate: 'release')]
    public function reserve(#[Input('orderId')] int $orderId): string
    {
        return "reservation-{$orderId}";
    }

    public function release(string $reservation): void {}

    #[SagaStep(id: 'charge', dependsOn: ['reserve'], compensate: 'refund', retry: 2, backoffMs: 200, timeoutMs: 5000)]
    #[StepEvent(topic: 'payments', type: 'payment.captured', key: 'orderId')]
    public function charge(#[Input('amount')] int $amount, #[FromStep('reserve')] string $reservation): string
    {
        return "receipt-for-{$reservation}";
    }

    public function refund(string $receipt): void {}

    #[OnSagaError]
    public function failed(\Throwable $error): void {}
}
```

A compensation receives the forward step's result. Start the saga through the `Orchestrator` (or `SagaEngine`
directly):

<!-- illustrative: a controller action that starts the saga -->
```php
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\Orchestrator;

final class PlaceOrder
{
    public function __construct(private readonly Orchestrator $orchestrator) {}

    /** @return array<string, mixed> */
    public function __invoke(int $orderId, int $amount): array
    {
        $outcome = $this->orchestrator->start(ExecutionPattern::Saga, 'checkout', ['orderId' => $orderId, 'amount' => $amount]);

        return ['id' => $outcome->correlationId, 'status' => $outcome->status->value];
    }
}
```

Steps can also be declared without a class through `SagaBuilder::saga('checkout')->step('reserve')->handler(…)
->compensation(…)->add()->build()`, and registered on the `SagaRegistry`.

### Compensation policies

`firefly.orchestration.saga.compensation-policy` decides how completed steps are undone:

| Policy | Behavior |
|---|---|
| `strict-sequential` (default) | Undo in reverse completion order, one at a time. |
| `grouped-parallel` | Undo layer by layer, in reverse layer order. |
| `retry-with-backoff` | Like `strict-sequential`, retrying each compensation with its `compensationRetry` and `compensationBackoffMs`. |
| `circuit-breaker` | Stop compensating once a `compensationCritical` step fails or the breaker opens; the saga ends `COMPENSATION_FAILED`, is dead-lettered, and lists the steps it left in `SagaResult::$uncompensatedSteps`. |
| `best-effort` | Run every compensation and record each failure without stopping. |

`firefly.orchestration.saga.compensation-error-handler` decides what one failed compensation does to the run:
`default`, `fail-fast`, `log-and-continue`, `retry`, `robust`, or the class name of your own
`CompensationErrorHandler` bean.

## Declaring a TCC

A TCC names its participants by `#[TccParticipant]` on separate beans. Each participant has a `#[TryMethod]`, a
`#[ConfirmMethod]` and a `#[CancelMethod]`; confirm and cancel receive the try result through `#[FromTry]`.
Participants try in `order`. If any try fails, every participant that tried is cancelled.

<!-- illustrative: one participant of a transfer TCC -->
```php
use Firefly\Container\Attributes\Service;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Tcc\Attributes\CancelMethod;
use Firefly\Orchestration\Tcc\Attributes\ConfirmMethod;
use Firefly\Orchestration\Tcc\Attributes\FromTry;
use Firefly\Orchestration\Tcc\Attributes\Tcc;
use Firefly\Orchestration\Tcc\Attributes\TccParticipant;
use Firefly\Orchestration\Tcc\Attributes\TryMethod;

#[Tcc(name: 'transfer', timeoutMs: 10000)]
final class TransferTcc {}

#[Service]
#[TccParticipant(tcc: 'transfer', id: 'debit', order: 1)]
final class DebitAccount
{
    #[TryMethod]
    public function hold(#[Input('amount')] int $amount): string
    {
        return "hold-{$amount}";
    }

    #[ConfirmMethod]
    public function commit(#[FromTry] string $hold): void {}

    #[CancelMethod]
    public function release(#[FromTry] string $hold): void {}
}
```

A confirmed TCC ends `CONFIRMED`, a cancelled one `CANCELED`. With `recovery.action` set to `resume`, a
process that died in `CONFIRMING` is driven on to `CONFIRMED`, confirming only the participants that had not
confirmed yet; `compensate` cancels every participant instead.

## Durable workflows

A workflow is a step graph whose steps may wait. `#[WaitForSignal]` parks the execution `WAITING` until
`WorkflowEngine::signal()` delivers the signal, whose payload the step reads as the variable
`signals.<name>`. `#[WaitForTimer]` parks it until the timer is due; the scheduled tick
(`firefly.orchestration.workflow.tick.*`) or `php artisan firefly:orchestration:tick` advances due workflows.
A due workflow the tick cannot drive (its definition is not deployed on that node, a handler throws outside a
step) is backed off, starting at one minute and doubling, so it never holds back the healthy rows behind it.
After `firefly.orchestration.workflow.max-resume-attempts` failed ticks in a row (default `5`, about fifteen
minutes) it is parked `FAILED` without compensation and dead-lettered. A database fault is not counted: it
escapes the tick, so the scheduler reports the outage.
`#[WaitForAll]` and `#[WaitForAny]` combine signals and timers, `#[ChildWorkflow]` starts another workflow and
waits for it, `condition` skips a step when an ExpressionLanguage expression over `input`, `results`,
`variables` and `headers` is false, and `#[WorkflowQuery]` answers a read-only question about a live execution.

<!-- illustrative: an onboarding workflow that waits for a signal and a timer -->
```php
use Firefly\Container\Attributes\Service;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Core\Argument\Variable;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Workflow\Attributes\WaitForSignal;
use Firefly\Orchestration\Workflow\Attributes\WaitForTimer;
use Firefly\Orchestration\Workflow\Attributes\Workflow;
use Firefly\Orchestration\Workflow\Attributes\WorkflowQuery;
use Firefly\Orchestration\Workflow\Attributes\WorkflowStep;

#[Service]
#[Workflow(id: 'onboarding')]
final class OnboardingWorkflow
{
    #[WorkflowStep(id: 'register')]
    public function register(#[Input('email')] string $email, ExecutionContext $ctx): string
    {
        $ctx->setSearchAttribute('email', $email);

        return "user-{$email}";
    }

    #[WorkflowStep(id: 'verify', dependsOn: ['register'])]
    #[WaitForSignal('email-verified', timeoutMs: 86400000)]
    public function verify(#[Variable('signals.email-verified')] mixed $proof): string
    {
        return 'verified';
    }

    #[WorkflowStep(id: 'welcome', dependsOn: ['verify'])]
    #[WaitForTimer(delayMs: 3600000, timerId: 'welcome')]
    public function welcome(): string
    {
        return 'welcomed';
    }

    #[WorkflowQuery('progress')]
    public function progress(ExecutionContext $ctx): int
    {
        return count($ctx->results());
    }
}
```

<!-- illustrative: driving the workflow from application code -->
```php
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;

final class Onboarding
{
    public function __construct(private readonly WorkflowEngine $workflows) {}

    public function begin(string $email): string
    {
        return $this->workflows->start('onboarding', ['email' => $email])->correlationId;
    }

    public function verified(string $correlationId, string $token): void
    {
        $this->workflows->signal($correlationId, 'email-verified', ['token' => $token]);
    }

    public function progress(string $correlationId): mixed
    {
        return $this->workflows->query($correlationId, 'progress');
    }
}
```

`suspend()`, `resume()`, `cancel()` and `compensate()` manage a live execution;
`upsertSearchAttributes()` and `search()` find executions by attribute. A step that returns `ContinueAsNew`
ends the run and starts a fresh one with new input, linked through the `x-firefly-continued-from` header.

### Compensating a workflow

When a workflow step fails (after its retries), the engine undoes every completed step that has a
compensation, newest first, dead-letters the failure and ends the execution `FAILED`. A step declares its
compensation inline, with `compensatable: true, compensationMethod: 'name'` on its `#[WorkflowStep]`, or as a
separate method marked `#[CompensationStep(compensates: 'step-id')]`, which makes the step compensatable on
its own. Either way the method receives the step's result as its implicit argument (and may take the usual
`#[Input]`, `#[Variable]` or `ExecutionContext` parameters); what it returns is recorded under
`compensationResults`. A step has one compensation: two `#[CompensationStep]` methods for one step, both forms
on one step, or a `#[CompensationStep]` naming a step the workflow does not declare are refused with a
`TopologyValidationException` when the definitions are scanned.

<!-- illustrative: a shipment workflow whose label is voided when the dispatch fails -->
```php
use Firefly\Container\Attributes\Service;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Workflow\Attributes\CompensationStep;
use Firefly\Orchestration\Workflow\Attributes\Workflow;
use Firefly\Orchestration\Workflow\Attributes\WorkflowStep;

#[Service]
#[Workflow(id: 'shipment')]
final class ShipmentWorkflow
{
    #[WorkflowStep(id: 'book')]
    public function book(#[Input('parcel')] string $parcel): string
    {
        return "label-{$parcel}";
    }

    #[CompensationStep(compensates: 'book')]
    public function voidLabel(string $label): string
    {
        return "voided {$label}";
    }

    #[WorkflowStep(id: 'dispatch', dependsOn: ['book'])]
    public function dispatch(#[Input('carrier')] string $carrier): string
    {
        return "dispatched via {$carrier}";
    }
}
```

`WorkflowEngine::compensate()` runs the same compensations on request for a live execution and ends it
`CANCELLED`.

### Queued (ASYNC) workflows

A workflow declared with `triggerMode: TriggerMode::Async` never runs inside `start()`. The start persists
the execution `PENDING` and dispatches a `RunWorkflowJob` that carries only the correlation id. A queue
worker then loads the execution from the persistence provider and runs it:

<!-- source: packages/orchestration/tests/CapstoneFixtures/StatementWorkflow.php -->
```php
#[Service]
#[Workflow(id: 'statement', triggerMode: TriggerMode::Async)]
final class StatementWorkflow
{
    public function __construct(private readonly Ledger $ledger) {}

    #[WorkflowStep(id: 'render')]
    public function render(#[Input('account')] string $account): string
    {
        $this->ledger->record("render {$account}");

        return "statement-{$account}";
    }
    // …
}
```

The job is dispatched on `firefly.orchestration.workflow.queue.connection` and `.name`. When either is unset,
the application's default connection or queue is used:

<!-- source: skeleton/config/firefly.php -->
```php
'queue' => [
    'connection' => env('FIREFLY_ORCHESTRATION_WORKFLOW_QUEUE_CONNECTION'),
    'name' => env('FIREFLY_ORCHESTRATION_WORKFLOW_QUEUE'),
],
```

A worker on that connection and queue runs the job:

```bash
php artisan queue:work database --queue=workflows
```

The worker is a different process, so ASYNC workflows need `cache` or `database` persistence. The `sync`
connection is the one exception: it runs the job inside `start()`, and the start returns the finished
execution. Redelivery is safe. A job delivered a second time, for example by a worker that died before it
acknowledged the job, finds the execution no longer `PENDING` and returns it unchanged, so no step runs twice.
The capstone in `packages/orchestration/tests/Capstone/CapstoneQueuedWorkflowTest.php` covers this path on
the `database` queue. Its integration twin covers it on PostgreSQL, MySQL and MariaDB. A SQL Server case is
written but has not yet run against a real server.

## Persistence, recovery and the dead-letter queue

`firefly.orchestration.persistence.provider` selects the store:

| Provider | Use |
|---|---|
| `memory` (default) | Tests and single-request flows. Nothing survives the process. |
| `cache` | Any Laravel cache store (`persistence.cache-store`), keys under `persistence.key-prefix`. |
| `database` | The `firefly_orchestration_*` tables on `persistence.connection`. Proven on PostgreSQL, MySQL, MariaDB and SQLite. SQL Server takes the same `upsert()` path (`MERGE`) and has integration tests behind `FIREFLY_SQLSRV_DSN`, but they have not yet run against a real server. |

Publish the migration with `php artisan vendor:publish --tag=firefly-orchestration-migrations`; the package also
loads it while `persistence.provider` or `dlq.store` is `database`.

Writers serialize per execution through the `firefly/scheduling` `DistributedLock` (`firefly.orchestration.lock.*`).
With `firefly.orchestration.recovery.enabled`, a scheduled sweep finds executions not updated for
`recovery.stale-threshold` and, by `recovery.action`, reports them (`report`), runs them on (`resume`) or undoes
them (`compensate`). The same sweep deletes terminal executions older than `persistence.retention-period`.

A failed execution becomes a dead letter (`firefly.orchestration.dlq.*`). Retrying one starts its definition
again with the original input and counts the retry on the entry.

## Events, triggers and schedules

With `firefly.orchestration.events.enabled`, every execution publishes `EXECUTION_STARTED`,
`EXECUTION_COMPLETED`, `STEP_COMPLETED` and `STEP_FAILED` through the `EventPublisher` to
`events.default-topic` (or `orchestration`); a workflow publishes its `STEP_COMPLETED` events only with
`publishEvents: true`. A `#[StepEvent]`, a `#[TccEvent]` or a workflow step's `outputEventType` publishes that
step's completion (for a TCC participant, its confirm) to the topic and type it names, whether or not
`events.enabled` is on. A definition's `triggerEventType` registers it on the event gateway, so an EDA event of that type starts it
with the event payload as input. `#[ScheduledSaga]`, `#[ScheduledTcc]` and `#[ScheduledWorkflow]` start a
definition on a cron, fixed-rate or fixed-delay cadence through `firefly/scheduling`, guarded by its distributed lock.
Lifecycle hooks are beans implementing `OrchestrationEvents` (extend `OrchestrationEventsAdapter` to override a
few of its 26 methods).

## Operating

- `GET /actuator/orchestration` (expose `orchestration` in `firefly.management.endpoints.web.exposure.include`)
  lists definitions with their topology layers, execution counts, dead letters and triggers;
  `…/executions`, `…/executions/{id}` and `…/dlq` drill down.
- The `orchestration` health component reports the store, open and stale executions and dead letters
  (`firefly.orchestration.health.enabled`).
- `php artisan firefly:orchestration:list`, `:show`, `:signal`, `:dlq`, `:dlq:retry` and `:tick`.
- With `firefly.orchestration.rest.enabled`, a REST surface under `rest.path` starts sagas, TCCs and
  workflows, signals, suspends, resumes and cancels workflows, runs queries and retries or deletes dead
  letters. It is an operator surface: protect it with your own middleware or network policy.
- With `firefly/observability`, executions and steps are timed (`orchestration_executions_seconds`,
  `orchestration_steps_seconds`), compensations and dead letters counted
  (`orchestration_compensations_total`, `orchestration_dead_letters_total`), and traced when
  `firefly.observability.tracing.orchestration.enabled` is on.

## Configuration

Every key lives under `firefly.orchestration` in `config/firefly.php`; the skeleton documents each with its
default.

| Keys | Purpose |
|---|---|
| `saga.enabled`, `saga.compensation-policy`, `saga.default-timeout`, `saga.compensation-error-handler` | Saga engine |
| `tcc.enabled`, `tcc.default-timeout`, `tcc.composition.enabled`, `tcc.composition.compensation-policy` | TCC engine |
| `workflow.enabled`, `workflow.queue.connection`, `workflow.queue.name`, `workflow.tick.enabled`, `workflow.tick.interval`, `workflow.max-resume-attempts` | Workflow engine and its tick |
| `persistence.provider`, `persistence.connection`, `persistence.cache-store`, `persistence.key-prefix`, `persistence.key-ttl`, `persistence.retention-period`, `persistence.cleanup-interval` | Execution store |
| `recovery.enabled`, `recovery.stale-threshold`, `recovery.action` | Recovery sweep |
| `dlq.enabled`, `dlq.store` | Dead-letter queue |
| `events.enabled`, `events.default-topic`, `event-gateway.enabled` | EDA events and triggers |
| `rest.enabled`, `rest.path` | REST surface |
| `health.enabled`, `metrics.enabled`, `tracing.enabled` | Health component, meters, spans |
| `validation.enabled`, `validation.fail-on-warning` | Boot-time topology validation |
| `lock.ttl`, `lock.wait` | Per-execution lock |
| `backpressure.strategy`, `backpressure.batch-size`, `backpressure.max-in-flight`, `backpressure.circuit-breaker.*` | Admission control |

## Limits

Steps of one topology layer run one after another. `layerConcurrency`, a step's `cpuBound` and a workflow
step's `async` are accepted so definitions port from the Java engine unchanged, and have no effect; the
actuator endpoint says so in its `notes`.
