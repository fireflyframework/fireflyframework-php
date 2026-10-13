<span class="eyebrow">Part III — Coordinating and Securing the Application · Chapter 9A</span>

# Sagas, TCC and Durable Workflows {.chtitle}

By the end of this chapter you will know what to do when one business operation spans more than one transaction — the line Chapter 9's `#[Transactional]` cannot cross. You will know the three patterns `firefly/orchestration` ships and when each one fits: a **saga** (`#[Saga]`, `#[SagaStep]`) runs forward steps in `dependsOn` order and undoes the completed ones with their compensations; a **TCC** (`#[Tcc]`, `#[TccParticipant]`, `#[TryMethod]`, `#[ConfirmMethod]`, `#[CancelMethod]`) reserves with every participant before it confirms any; a **durable workflow** (`#[Workflow]`, `#[WorkflowStep]`) waits for signals (`#[WaitForSignal]`) and timers (`#[WaitForTimer]`) across processes. You will run Lumen's `payout` saga end to end and watch it compensate in reverse, and you will know where its checkpoints, its recovery sweep and its dead letters live (`firefly.orchestration.persistence.provider`, `firefly:orchestration:dlq`), how the scheduler's distributed lock (`firefly.scheduling.lock.provider`) keeps a timer tick on one node, and what PHP's synchronous model means for steps in the same layer.

!!! note "New term: saga"
    A **saga** is a sequence of local transactions, each of which commits on its own, paired with a **compensation** for every step that changed something — an action that semantically undoes it. If step three fails, nothing rolls steps one and two back for you; the saga runs their compensations, newest first. A compensation is not a rollback: a refunded payment is a second payment in the other direction, and both are in the ledger.

---

## When one transaction is not enough

Chapter 9 ended on `Transfer`: two wallets, one database, one `#[Transactional]` boundary, and money that cannot vanish because the debit and the credit commit or roll back together. A **payout** is different. The money leaves the wallet in Lumen's database and arrives at a bank, which has its own database, its own transactions and its own idea of when something is final. No transaction can enlist both, so the honest design is a sequence of steps that each commit, and an explicit undo for each one that the next step's failure would otherwise strand. That is a saga, and the next section is the one Lumen runs.

---

## Lumen's payout saga

The bank is a port, because it is another system:

<!-- source: samples/lumen/src/Application/Payout/BankGateway.php -->
```php
interface BankGateway
{
    public function submit(string $iban, int $amountMinor): string;

    public function confirm(string $reference): void;

    public function cancel(string $reference): void;
}
```

`SimulatedBankGateway` is the sample's adapter: it refuses an IBAN that starts with `XX` when the transfer is submitted, and it refuses to confirm one above its daily limit of 1,000.00 — the two refusals a real bank gives, which is what the saga needs something to compensate. The saga itself is a bean with one `#[SagaStep]` per forward step:

<!-- source: samples/lumen/src/Application/Payout/PayoutSaga.php -->
```php
#[Service]
#[Saga(name: 'payout')]
final class PayoutSaga
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly BankGateway $bank,
    ) {}

    #[SagaStep(id: 'debit', compensate: 'refund')]
    public function debit(#[Input('walletId')] string $walletId, #[Input('amountMinor')] int $amountMinor): int
    {
        $balance = $this->commands->send(new Withdraw($walletId, $amountMinor));

        return is_int($balance) ? $balance : 0;
    }

    public function refund(#[Input('walletId')] string $walletId, #[Input('amountMinor')] int $amountMinor): void
    {
        $this->commands->send(new Deposit($walletId, $amountMinor));
    }

    #[SagaStep(id: 'submit', dependsOn: ['debit'], compensate: 'cancel')]
    public function submit(#[Input('iban')] string $iban, #[Input('amountMinor')] int $amountMinor): string
    {
        return $this->bank->submit($iban, $amountMinor);
    }

    public function cancel(string $reference): void
    {
        $this->bank->cancel($reference);
    }

    #[SagaStep(id: 'confirm', dependsOn: ['submit'])]
    public function confirm(#[FromStep('submit')] string $reference): string
    {
        $this->bank->confirm($reference);

        return $reference;
    }
}
```

Read it as three local transactions and two undos. `debit` sends the same `Withdraw` command the REST endpoint sends, so it runs in its own `#[Transactional]` boundary and under its own `#[PreAuthorize]` — a saga step is not a back door around method security. `compensate: 'refund'` names the method that undoes it; `dependsOn` orders the steps into topology layers, and `#[Input('…')]` reads a key of the execution's input, `#[FromStep('submit')]` the result an earlier step returned. A compensation receives the forward step's result as its implicit argument (`cancel()` gets the bank reference `submit()` returned) and may also ask for `#[Input]` values, which is how `refund()` knows how much to give back. `confirm` has no compensation because nothing runs after it.

---

## Running it, and watching it undo itself

`SagaEngine::execute('payout', $input)` runs the saga and returns a `SagaResult`. The sample's test drives the case that matters most — the bank accepts the transfer and then refuses to execute it:

<!-- source: samples/lumen/tests/Application/PayoutSagaTest.php -->
```php
    // 1,500.00 is over the simulated bank's daily limit: submitted, then refused at confirmation.
    $result = $this->app()->make(SagaEngine::class)->execute('payout', ['walletId' => $id, 'iban' => 'ES9121000418450200051332', 'amountMinor' => 150000]);

    expect($result->success)->toBeFalse()
        ->and($result->status)->toBe(ExecutionStatus::Failed)
        ->and($result->firstErrorStepId())->toBe('confirm')
        ->and($result->compensatedSteps())->toBe(['debit', 'submit'])
        ->and($this->app()->make(SimulatedBankGateway::class)->calls)->toBe(['submit bank-1', 'limit bank-1', 'cancel bank-1'])
        ->and($queries->ask(new GetBalance($id)))->toBe(200000)
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $result->correlationId)->value('status'))->toBe('FAILED');
```

The bank's own call log tells the story in order: the transfer was submitted, refused at confirmation, and canceled; then the wallet was refunded, and its balance is exactly what it was. The three outcomes the test file covers:

| Input | Fails at | Compensated | Bank calls | Wallet afterwards |
|---|---|---|---|---|
| a valid IBAN, 200.00 | — | — | `submit bank-1`, `confirm bank-1` | debited by 200.00 |
| `XX0012345678`, 200.00 | `submit` | `debit` | `refused XX0012345678` | unchanged |
| a valid IBAN, 1,500.00 | `confirm` | `submit`, then `debit` | `submit bank-1`, `limit bank-1`, `cancel bank-1` | unchanged |

`compensatedSteps()` lists the undone steps in declaration order; the order they **ran** in is the reverse — the default policy undoes the newest completed step first, which is why the bank was told to cancel before the wallet was refunded. The tutorial's greeting-card saga, whose journal records every call, asserts that order call by call in `tests/TutorialGreetingCardSagaTest.php`: the charge is refunded, then the card is released.

---

## Over HTTP

`PayoutController` exposes the saga at `POST /api/v1/wallets/{id}/payouts`:

<!-- source: samples/lumen/src/Web/PayoutController.php -->
```php
        $result = $this->sagas->execute('payout', ['walletId' => $id, 'iban' => $body->iban, 'amountMinor' => $body->amount_minor]);

        if (! $result->success) {
            // A compensation that did not finish: money may have left the wallet and not come back. That is never a
            // business refusal; it is the one outcome an operator must act on, so it gets its own server-side answer
            // naming the steps left undone and the payout id the execution is checkpointed and dead-lettered under.
            // Asked of the steps as well as the status: under the default strict-sequential policy a compensation
            // that throws marks its step COMPENSATION_FAILED and the error handler continues, so the saga still
            // ends FAILED, while a policy that stops the sweep ends it COMPENSATION_FAILED.
            $undone = $this->undoneSteps($result);
            if ($undone !== [] || $result->status === ExecutionStatus::CompensationFailed) {
                throw (new FireflyException(
                    sprintf('Payout %s failed at step "%s" and was not fully undone; steps left uncompensated: %s.', $result->correlationId, (string) $this->failingStep($result), implode(', ', $undone)),
                    'PAYOUT_COMPENSATION_FAILED',
                    500,
                    ErrorCategory::Internal,
                    ErrorSeverity::Critical,
                    $result->error,
                ))->withExtensions(['payout_id' => $result->correlationId, 'uncompensated_steps' => $undone]);
            }

            // The debit itself was refused, so nothing was undone: the bus's own refusal — 401, 403, 404, or the 409
            // of an overdraw — is the honest answer, exactly as POST /withdraw would have given it. Asked of the debit
            // step's own status, not firstErrorStepId(), which also counts a step whose compensation failed.
            $debit = $result->steps['debit'] ?? null;
            if ($debit !== null && $debit->status === StepStatus::Failed && $result->error !== null) {
                throw $result->error;
            }

            throw new ConflictException(
                sprintf('Payout %s failed at step "%s" and ended %s: %s', $result->correlationId, (string) $this->failingStep($result), $result->status->value, $result->error?->getMessage() ?? 'no error was recorded'),
                'PAYOUT_FAILED',
                $result->error,
            );
        }
```

Three kinds of failure, three answers. When a compensation did not finish — the refund itself threw, or a policy stopped the sweep — money may have left the wallet and not come back, so that is never reported as a business refusal: the client gets a 500 `PAYOUT_COMPENSATION_FAILED` whose `payout_id` and `uncompensated_steps` tell an operator which execution to look up and what is left to undo. The check reads the steps as well as the status, because under the default handler a compensation that throws marks its step `COMPENSATION_FAILED` and the saga still ends `FAILED`. When the debit itself was refused, nothing happened and nothing was undone, so the controller rethrows the bus's own exception and the web layer renders it exactly as it renders a failed withdraw — an overdraw is the same 409 problem+json, a missing role the same 403. When a later step failed and every completed step was undone, the client gets a 409 `PAYOUT_FAILED` whose message names the step and the final status. `PayoutRestTest` covers all of them, the failed refund included, and a successful payout answers 201 with the bank's reference.

---

## Compensation policies

`firefly.orchestration.saga.compensation-policy` decides how completed steps are undone:

| Policy | Behavior |
|---|---|
| `strict-sequential` (default) | Undo in reverse completion order, one at a time. |
| `grouped-parallel` | Undo layer by layer, in reverse layer order (sequentially inside a layer — PHP is synchronous). |
| `retry-with-backoff` | Like `strict-sequential`, retrying each compensation with its `compensationRetry` and `compensationBackoffMs`. |
| `circuit-breaker` | Stop compensating once a `compensationCritical` step fails or the breaker opens; the saga ends `COMPENSATION_FAILED`, is dead-lettered, and lists what it left in `SagaResult::$uncompensatedSteps`. |
| `best-effort` | Run every compensation and record each failure without stopping. |

`firefly.orchestration.saga.compensation-error-handler` decides what one failed compensation does to the run — `default`, `fail-fast`, `log-and-continue`, `retry`, `robust`, or the class name of your own `CompensationErrorHandler` bean. A step can also carry `retry`, `backoffMs`, `timeoutMs` and an `idempotencyKey` on its `#[SagaStep]`; a step's retries run before the saga gives up on it.

---

## Checkpoints, recovery and the dead-letter queue

Every step boundary writes an `ExecutionState` checkpoint through the `ExecutionPersistenceProvider` port. `memory` is the default and survives nothing; `cache` uses any Laravel cache store; `database` uses three portable tables, proven on PostgreSQL, MySQL, MariaDB, SQL Server and SQLite. Lumen's harness runs the payout saga on `database`, which is why the test above can read `firefly_orchestration_executions`:

<!-- source: skeleton/config/firefly.php -->
```php
'persistence' => [
    'provider' => env('FIREFLY_ORCHESTRATION_PERSISTENCE', 'memory'),
    'connection' => env('FIREFLY_ORCHESTRATION_DB_CONNECTION'),
    'cache-store' => env('FIREFLY_ORCHESTRATION_CACHE_STORE'),
    'key-prefix' => 'orchestration:',
    'key-ttl' => null,
    'retention-period' => env('FIREFLY_ORCHESTRATION_RETENTION', 'P7D'),
    'cleanup-interval' => '1h',
],
// …
'recovery' => [
    'enabled' => env('FIREFLY_ORCHESTRATION_RECOVERY_ENABLED', true),
    'stale-threshold' => env('FIREFLY_ORCHESTRATION_STALE_THRESHOLD', '1h'),
    'action' => env('FIREFLY_ORCHESTRATION_RECOVERY_ACTION', 'report'),
],
// …
'dlq' => [
    'enabled' => true,
    'store' => env('FIREFLY_ORCHESTRATION_DLQ_STORE', 'memory'),
],
```

The checkpoints are what make a crash survivable. A scheduled recovery sweep finds executions not updated for `recovery.stale-threshold` and, by `recovery.action`, reports them (`report`), runs them on from their last checkpoint (`resume`) or undoes them (`compensate`); the same sweep deletes terminal executions older than `persistence.retention-period`. A failed execution becomes a dead letter — one entry per failed step, `memory` or `database` — and retrying one starts the same definition again with the same input. Operators reach all of it from the command line:

<!-- illustrative: the commands an operator runs against the database store; the execution id comes from the list output, the dead-letter id from the dlq output -->
```bash
php artisan vendor:publish --tag=firefly-orchestration-migrations
php artisan migrate
php artisan firefly:orchestration:list --name=payout --status=failed
php artisan firefly:orchestration:show 7c1d0e8a-5b4f-4c3e-9a21-0f6e2d4b8c13
php artisan firefly:orchestration:dlq --name=payout
php artisan firefly:orchestration:dlq:retry 3f2a9c4e-8d1b-4e6f-a0c7-5b9e2d1f4a86
```

---

## TCC: reserve everywhere, then confirm

A saga commits each step and undoes it later. **TCC** — try, confirm, cancel — is the stricter shape for when nothing may be visible until everyone agreed: each participant first *tries* (reserves, without committing to the outcome), and only when every try succeeded are they all *confirmed*; if any try fails, every participant that tried is *canceled*. The participants are separate beans named by `#[TccParticipant]`, in `order`; this is the orchestration capstone's debit side of a transfer:

<!-- source: packages/orchestration/tests/CapstoneFixtures/TransferTcc.php -->
```php
#[Tcc(name: 'transfer', maxRetries: 0, backoffMs: 1)]
final class TransferTcc {}
```

<!-- source: packages/orchestration/tests/CapstoneFixtures/DebitAccount.php -->
```php
#[Service]
#[TccParticipant(tcc: 'transfer', id: 'debit', order: 1)]
final class DebitAccount
{
    public function __construct(private readonly Ledger $ledger) {}

    #[TryMethod]
    public function hold(#[Input('amount')] int $amount): string
    {
        $this->ledger->record("hold {$amount}");

        return "hold-{$amount}";
    }

    #[ConfirmMethod]
    public function commit(#[FromTry] string $hold): void
    {
        $this->ledger->record("commit {$hold}");
    }

    #[CancelMethod]
    public function release(#[FromTry] string $hold): void
    {
        $this->ledger->record("release {$hold}");
    }
}
```

`#[FromTry]` hands confirm and cancel the value the try returned — here the hold's id — so a participant never has to look its own reservation up again. A confirmed TCC ends `CONFIRMED`, a canceled one `CANCELED`; with `recovery.action=resume`, a process that died in `CONFIRMING` is driven on to `CONFIRMED`, confirming only the participants that had not confirmed yet.

---

## Durable workflows: waiting for the world

Sagas and TCCs run start to finish inside one call. A **workflow** may wait — for a person to click a link, for a day to pass — and survive the process that started it. `#[WaitForSignal]` parks the execution `WAITING` until a signal is delivered; `#[WaitForTimer]` parks it until the timer is due. The capstone's onboarding workflow does both:

<!-- source: packages/orchestration/tests/CapstoneFixtures/OnboardingWorkflow.php -->
```php
#[Service]
#[Workflow(id: 'onboarding')]
final class OnboardingWorkflow
{
    public function __construct(private readonly Ledger $ledger) {}

    #[WorkflowStep(id: 'register')]
    public function register(#[Input('email')] string $email, ExecutionContext $ctx): string
    {
        $ctx->setSearchAttribute('email', $email);
        $this->ledger->record("register {$email}");

        return "user-{$email}";
    }

    #[WorkflowStep(id: 'verify', dependsOn: ['register'])]
    #[WaitForSignal('email-verified')]
    public function verify(#[Variable('signals.email-verified')] mixed $proof): string
    {
        $this->ledger->record('verified');

        return 'verified';
    }

    #[WorkflowStep(id: 'cool-off', dependsOn: ['verify'])]
    #[WaitForTimer(delayMs: 1, timerId: 'cool-off')]
    public function coolOff(): string
    {
        $this->ledger->record('cooled off');

        return 'cooled';
    }

    #[WorkflowQuery('progress')]
    public function progress(ExecutionContext $ctx): int
    {
        return count($ctx->results());
    }
}
```

`WorkflowEngine::start()` runs `register` and parks at `verify`; the checkpoint is written and the request ends. Later — another request, another process — `WorkflowEngine::signal($correlationId, 'email-verified', $payload)` delivers the signal, which the step reads as the variable `signals.email-verified`, and the workflow runs on until the timer parks it again. The scheduled tick (`firefly.orchestration.workflow.tick.*`, every minute by default) advances due timers; a due workflow the tick cannot drive is backed off, doubling from one minute, and parked `FAILED` and dead-lettered after `workflow.max-resume-attempts` failed ticks. `#[WorkflowQuery]` answers a read-only question about a live execution, and `setSearchAttribute()` makes it findable. `#[WaitForAll]`, `#[WaitForAny]`, `#[ChildWorkflow]`, step conditions, `ContinueAsNew` and queued (`triggerMode: TriggerMode::Async`) workflows are in the module guide.

<!-- illustrative: delivering the signal and advancing timers from the command line -->
```bash
php artisan firefly:orchestration:signal 7c1d0e8a-5b4f-4c3e-9a21-0f6e2d4b8c13 email-verified --payload='{"token":"t-1"}'
php artisan firefly:orchestration:tick
```

---

## One node at a time: the scheduler's lock

The tick, the recovery sweep and every `#[ScheduledSaga]`, `#[ScheduledTcc]` and `#[ScheduledWorkflow]` run on the framework's schedule exactly as a `#[Scheduled]` task of your own does, each under a lock name of its own; and every writer of one execution takes a per-execution lock first. On one server, nothing more is needed. On three, three schedulers fire the same tick in the same minute — so a task guarded with `#[Scheduled(lock: …)]` asks a `DistributedLock` first, and only the node that wins runs it. `firefly.scheduling.lock.provider` picks the backend:

<!-- source: skeleton/config/firefly.php -->
```php
'scheduling' => [
    'lock' => [
        'provider' => env('FIREFLY_SCHEDULING_LOCK', 'none'),
        // …
    ],
    // …
],
```

| `firefly.scheduling.lock.provider` | Backend | Works on | Notes |
|---|---|---|---|
| `none` (default) | `NoneLock` | anything | every acquire succeeds — correct for one node, no exclusion across several |
| `cache` | `CacheLock` | a lock-capable cache store | `database`, `redis` or `memcached` across nodes; `array` is per process |
| `database` | `DatabaseLeaseLock` | any Laravel database connection | a lease row per lock name in `firefly_scheduling_locks`; the TTL is honored on every driver |
| `mysql` | `MySqlNamedLock` | MySQL, MariaDB | `GET_LOCK`/`RELEASE_LOCK`, held by the session, no TTL |
| `postgres` | `PgAdvisoryLock` | PostgreSQL | session-scoped advisory lock |

`DatabaseLeaseLock` is the one that needs nothing beyond the database you already have: acquiring is a conditional `UPDATE` that takes over an expired lease, or an `INSERT` when the row does not exist (inside a savepoint when a transaction is open, so a lost race never aborts your unit of work); releasing deletes the row only while it still carries this node's token, so a task that overran its TTL cannot release the lease another node has taken since. The orchestration lock rides on the same provider, held at most `firefly.orchestration.lock.ttl` and waited for at most `firefly.orchestration.lock.wait`:

<!-- source: skeleton/config/firefly.php -->
```php
'lock' => [
    'ttl' => '5m',
    'wait' => '5s',
],
```

---

## Events, triggers and operating it

- **Lifecycle events.** With `firefly.orchestration.events.enabled`, every execution publishes `EXECUTION_STARTED`, `EXECUTION_COMPLETED`, `STEP_COMPLETED` and `STEP_FAILED` through the `EventPublisher` (Chapter 8) — a workflow publishes its step events only with `publishEvents: true`. A `#[StepEvent]` on a step publishes that step's completion to the topic and type it names, whether or not the lifecycle events are on.
- **Triggers.** A definition's `triggerEventType` starts it from an event-bus event with the event's payload as input; the execution's correlation id is derived from the event id, so a redelivered event finds its execution already there and is acknowledged as a no-op.
- **Schedules.** `#[ScheduledSaga]`, `#[ScheduledTcc]` and `#[ScheduledWorkflow]` start a definition on a cron, fixed-rate or fixed-delay cadence, under the lock above.
- **Seeing it.** `GET /actuator/orchestration` (once exposed) lists definitions with their topology layers, execution counts, dead letters and triggers; the `orchestration` health component reports the store, open and stale executions and dead letters; with `firefly/observability`, executions and steps are timed and traced. `firefly:cache` compiles every `#[Saga]`, `#[Tcc]` and `#[Workflow]` into `orchestration.php`, and the topology is validated at boot — a cycle, an unknown `dependsOn` or a missing compensation refuses to start.

---

## What PHP's synchronous model means

Steps of one topology layer run one after another, in declaration order: a layer is a guarantee that every dependency finished before a dependent starts, not a promise of parallelism. `layerConcurrency`, a step's `cpuBound` and a workflow step's `async` are accepted so definitions port unchanged from the Java engine, and have no effect; the actuator endpoint says so in its `notes`. For work that should leave the request, use a queued workflow, or start the saga from an event a worker consumes.

!!! laravel "Laravel parity"
    Laravel's `Bus::chain()` runs jobs in order and stops at the first failure; `Bus::batch()` runs them together and calls you back. Neither knows how to undo the jobs that already succeeded, neither persists a step-by-step checkpoint you can resume from, and neither can wait three days for a signal. `firefly/orchestration` keeps Laravel's queue for what it is good at — the ASYNC workflow job is an ordinary queued job — and adds the compensation, the checkpoints and the durable waits.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| `#[Saga]` / `#[SagaStep]` | Forward steps in `dependsOn` layers, each its own unit of work, with `compensate:` naming its undo |
| Compensation | Undoes completed steps newest first (`strict-sequential`), or under four other policies; a refund, not a rollback |
| `SagaEngine::execute()` / `SagaResult` | Runs a saga in process; `success`, `status`, `firstErrorStepId()`, `compensatedSteps()` |
| TCC | `#[TryMethod]` everywhere first, then `#[ConfirmMethod]` everywhere, or `#[CancelMethod]` for every participant that tried |
| Durable workflow | `#[WaitForSignal]` / `#[WaitForTimer]` park it `WAITING`; a signal or the tick resumes it from its checkpoint |
| `firefly.orchestration.persistence.provider` | `memory`, `cache` or `database` checkpoints; recovery reports, resumes or compensates stale executions |
| Dead-letter queue | One entry per failed step; `firefly:orchestration:dlq` lists, `firefly:orchestration:dlq:retry` starts it again |
| `firefly.scheduling.lock.provider` | `none`, `cache`, `database`, `mysql` or `postgres` — keeps a scheduled tick on one node |

---

## Try it yourself {.exercises}

1. **Change the policy.** Run `PayoutSagaTest` with `firefly.orchestration.saga.compensation-policy=best-effort` added to `LumenOrchestrationTestCase` and confirm the three outcomes do not change — then explain why: no compensation in the payout saga ever fails.
2. **Make a compensation fail.** In a scratch copy, make `SimulatedBankGateway::cancel()` throw, rerun the confirmation-failure test, and read the final status, each step's status in `$result->steps` and `$result->uncompensatedSteps` — once with the default compensation error handler and once with `firefly.orchestration.saga.compensation-error-handler=fail-fast`. Which of the two refunded the wallet, and which answer would `PayoutController` give for each?
3. **Survive a restart.** Copy `OnboardingWorkflow` into an application of your own, point `firefly.orchestration.persistence.provider=database` at a real server, start the workflow in one PHP process, and deliver `email-verified` from a second one with `php artisan firefly:orchestration:signal`.
