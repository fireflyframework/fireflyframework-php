<span class="eyebrow">Parte III — Coordinar y Asegurar la Aplicación · Capítulo 9A</span>

# Sagas, TCC y Workflows Duraderos {.chtitle}

Al terminar este capítulo sabrás qué hacer cuando una operación de negocio abarca más de una transacción — la línea que el `#[Transactional]` del Capítulo 9 no puede cruzar. Conocerás los tres patrones que distribuye `firefly/orchestration` y cuándo encaja cada uno: una **saga** (`#[Saga]`, `#[SagaStep]`) ejecuta pasos hacia delante en el orden de `dependsOn` y deshace los ya completados con sus compensaciones; un **TCC** (`#[Tcc]`, `#[TccParticipant]`, `#[TryMethod]`, `#[ConfirmMethod]`, `#[CancelMethod]`) reserva con cada participante antes de confirmar a ninguno; un **workflow duradero** (`#[Workflow]`, `#[WorkflowStep]`) espera señales (`#[WaitForSignal]`) y temporizadores (`#[WaitForTimer]`) a través de procesos. Ejecutarás de principio a fin la saga `payout` de Lumen y la verás compensar en orden inverso, y sabrás dónde viven sus puntos de control, su barrido de recuperación y sus cartas muertas (`firefly.orchestration.persistence.provider`, `firefly:orchestration:dlq`), cómo el cerrojo distribuido del planificador (`firefly.scheduling.lock.provider`) mantiene el tic de un temporizador en un solo nodo, y qué significa el modelo síncrono de PHP para los pasos de una misma capa.

!!! note "Término nuevo: saga"
    Una **saga** es una secuencia de transacciones locales, cada una de las cuales confirma por su cuenta, acompañada de una **compensación** para cada paso que cambió algo — una acción que lo deshace semánticamente. Si el paso tres falla, nada revierte por ti los pasos uno y dos; la saga ejecuta sus compensaciones, empezando por la más reciente. Una compensación no es un rollback: un pago reembolsado es un segundo pago en sentido contrario, y los dos quedan en el libro mayor.

---

## Cuando una transacción no basta

El Capítulo 9 terminó con `Transfer`: dos monederos, una base de datos, una frontera `#[Transactional]`, y un dinero que no puede desaparecer porque el cargo y el abono se confirman o se revierten juntos. Un **pago saliente** es distinto. El dinero sale del monedero en la base de datos de Lumen y llega a un banco, que tiene su propia base de datos, sus propias transacciones y su propia idea de cuándo algo es definitivo. Ninguna transacción puede abarcar las dos, así que el diseño honesto es una secuencia de pasos que confirman cada uno, y una forma explícita de deshacer cada uno de los que el fallo del paso siguiente dejaría colgados. Eso es una saga, y la sección siguiente es la que ejecuta Lumen.

---

## La saga de pagos salientes de Lumen

El banco es un puerto, porque es otro sistema:

<!-- source: samples/lumen/src/Application/Payout/BankGateway.php -->
```php
interface BankGateway
{
    public function submit(string $iban, int $amountMinor): string;

    public function confirm(string $reference): void;

    public function cancel(string $reference): void;
}
```

`SimulatedBankGateway` es el adaptador del sample: rechaza un IBAN que empieza por `XX` cuando se envía la transferencia, y se niega a confirmar una que supere su límite diario de 1.000,00 — los dos rechazos que da un banco real, que es justo lo que la saga necesita para tener algo que compensar. La saga en sí es un bean con un `#[SagaStep]` por cada paso hacia delante:

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

Léela como tres transacciones locales y dos formas de deshacer. `debit` envía el mismo comando `Withdraw` que envía el endpoint REST, así que se ejecuta en su propia frontera `#[Transactional]` y bajo su propio `#[PreAuthorize]` — un paso de saga no es una puerta trasera para saltarse la seguridad de métodos. `compensate: 'refund'` nombra el método que lo deshace; `dependsOn` ordena los pasos en capas de la topología, y `#[Input('…')]` lee una clave de la entrada de la ejecución, `#[FromStep('submit')]` el resultado que devolvió un paso anterior. Una compensación recibe el resultado del paso hacia delante como argumento implícito (`cancel()` recibe la referencia bancaria que devolvió `submit()`) y además puede pedir valores `#[Input]`, que es como `refund()` sabe cuánto tiene que devolver. `confirm` no tiene compensación porque no se ejecuta nada después de él.

---

## Ejecutarla, y verla deshacerse

`SagaEngine::execute('payout', $input)` ejecuta la saga y devuelve un `SagaResult`. El test del sample recorre el caso que más importa — el banco acepta la transferencia y luego se niega a ejecutarla:

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

El propio registro de llamadas del banco cuenta la historia en orden: la transferencia se envió, se rechazó en la confirmación y se canceló; después se reembolsó el monedero, y su saldo es exactamente el que era. Los tres desenlaces que cubre el fichero de test:

| Entrada | Falla en | Compensado | Llamadas al banco | Monedero después |
|---|---|---|---|---|
| un IBAN válido, 200,00 | — | — | `submit bank-1`, `confirm bank-1` | debitado en 200,00 |
| `XX0012345678`, 200,00 | `submit` | `debit` | `refused XX0012345678` | sin cambios |
| un IBAN válido, 1.500,00 | `confirm` | `submit`, luego `debit` | `submit bank-1`, `limit bank-1`, `cancel bank-1` | sin cambios |

`compensatedSteps()` enumera los pasos deshechos en orden de declaración; el orden en que se **ejecutaron** es el inverso — la política por defecto deshace primero el paso completado más reciente, y por eso al banco se le pidió cancelar antes de que se reembolsara el monedero. La saga de tarjetas de felicitación del tutorial, cuyo diario registra cada llamada, comprueba ese orden llamada a llamada en `tests/TutorialGreetingCardSagaTest.php`: primero se reembolsa el cobro y después se libera la tarjeta.

---

## Sobre HTTP

`PayoutController` expone la saga en `POST /api/v1/wallets/{id}/payouts`:

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

Tres tipos de fallo, tres respuestas. Cuando una compensación no terminó — el propio reembolso lanzó una excepción, o una política detuvo el barrido — puede que el dinero haya salido del monedero y no haya vuelto, así que eso nunca se informa como un rechazo de negocio: el cliente recibe un 500 `PAYOUT_COMPENSATION_FAILED` cuyos `payout_id` y `uncompensated_steps` le dicen a un operador qué ejecución buscar y qué queda por deshacer. La comprobación mira los pasos además del estado, porque con el manejador por defecto una compensación que lanza marca su paso `COMPENSATION_FAILED` y la saga sigue terminando `FAILED`. Cuando el propio cargo se rechazó, no pasó nada y no se deshizo nada, así que el controlador relanza la excepción del propio bus y la capa web la presenta exactamente igual que presenta un withdraw fallido — un descubierto es el mismo 409 problem+json, un rol que falta el mismo 403. Cuando falló un paso posterior y se deshicieron todos los pasos completados, el cliente recibe un 409 `PAYOUT_FAILED` cuyo mensaje nombra el paso y el estado final. `PayoutRestTest` los cubre todos, incluido el reembolso fallido, y un pago saliente que sale bien responde 201 con la referencia del banco.

---

## Políticas de compensación

`firefly.orchestration.saga.compensation-policy` decide cómo se deshacen los pasos completados:

| Política | Comportamiento |
|---|---|
| `strict-sequential` (por defecto) | Deshace en orden inverso de finalización, de uno en uno. |
| `grouped-parallel` | Deshace capa a capa, en orden inverso de capas (secuencialmente dentro de una capa — PHP es síncrono). |
| `retry-with-backoff` | Como `strict-sequential`, reintentando cada compensación con su `compensationRetry` y su `compensationBackoffMs`. |
| `circuit-breaker` | Deja de compensar en cuanto falla un paso `compensationCritical` o se abre el breaker; la saga termina `COMPENSATION_FAILED`, pasa a carta muerta y enumera lo que dejó en `SagaResult::$uncompensatedSteps`. |
| `best-effort` | Ejecuta todas las compensaciones y registra cada fallo sin detenerse. |

`firefly.orchestration.saga.compensation-error-handler` decide qué le hace a la ejecución una compensación fallida — `default`, `fail-fast`, `log-and-continue`, `retry`, `robust`, o el nombre de clase de tu propio bean `CompensationErrorHandler`. Un paso también puede llevar `retry`, `backoffMs`, `timeoutMs` y una `idempotencyKey` en su `#[SagaStep]`; los reintentos de un paso se ejecutan antes de que la saga lo dé por perdido.

---

## Puntos de control, recuperación y la cola de cartas muertas

Cada frontera de paso escribe un punto de control `ExecutionState` a través del puerto `ExecutionPersistenceProvider`. `memory` es el valor por defecto y no sobrevive a nada; `cache` usa cualquier store de caché de Laravel; `database` usa tres tablas portables, probadas en PostgreSQL, MySQL, MariaDB, SQL Server y SQLite. Un cuarto, `event-sourced`, guarda cada ejecución como un flujo de eventos en el almacén de eventos (Capítulo 9B). El harness de Lumen ejecuta la saga de pagos salientes sobre `database`, y por eso el test de arriba puede leer `firefly_orchestration_executions`:

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

Los puntos de control son lo que hace sobrevivible una caída. Un barrido de recuperación programado encuentra las ejecuciones que no se han actualizado en `recovery.stale-threshold` y, según `recovery.action`, las informa (`report`), las continúa desde su último punto de control (`resume`) o las deshace (`compensate`); el mismo barrido borra las ejecuciones terminadas más antiguas que `persistence.retention-period`. Una ejecución fallida se convierte en una carta muerta — una entrada por paso fallido, en `memory` o en `database` — y reintentar una arranca otra vez la misma definición con la misma entrada. Los operadores llegan a todo ello desde la línea de comandos:

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

## TCC: reservar en todas partes y luego confirmar

Una saga confirma cada paso y lo deshace después. **TCC** — try, confirm, cancel — es la forma más estricta para cuando nada puede ser visible hasta que todos estén de acuerdo: cada participante primero *intenta* (reserva, sin comprometerse con el resultado), y solo cuando todos los intentos salieron bien se *confirman* todos; si falla algún intento, se *cancela* a cada participante que lo intentó. Los participantes son beans separados nombrados por `#[TccParticipant]`, en `order`; este es el lado del cargo de una transferencia en el capstone de orquestación:

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

`#[FromTry]` les pasa a confirm y a cancel el valor que devolvió el try — aquí el id de la retención — así que un participante nunca tiene que volver a buscar su propia reserva. Un TCC confirmado termina `CONFIRMED`, uno cancelado `CANCELED`; con `recovery.action=resume`, un proceso que murió en `CONFIRMING` se lleva hasta `CONFIRMED`, confirmando solo a los participantes que aún no habían confirmado.

---

## Workflows duraderos: esperar al mundo

Las sagas y los TCC se ejecutan de principio a fin dentro de una llamada. Un **workflow** puede esperar — a que una persona pulse un enlace, a que pase un día — y sobrevivir al proceso que lo arrancó. `#[WaitForSignal]` aparca la ejecución en `WAITING` hasta que se entrega una señal; `#[WaitForTimer]` la aparca hasta que vence el temporizador. El workflow de alta del capstone hace las dos cosas:

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

`WorkflowEngine::start()` ejecuta `register` y se aparca en `verify`; se escribe el punto de control y la petición termina. Más tarde — otra petición, otro proceso — `WorkflowEngine::signal($correlationId, 'email-verified', $payload)` entrega la señal, que el paso lee como la variable `signals.email-verified`, y el workflow sigue hasta que el temporizador vuelve a aparcarlo. El tic programado (`firefly.orchestration.workflow.tick.*`, cada minuto por defecto) hace avanzar los temporizadores vencidos; un workflow vencido que el tic no puede mover se pospone, duplicando desde un minuto, y se aparca en `FAILED` y pasa a carta muerta tras `workflow.max-resume-attempts` tics fallidos. `#[WorkflowQuery]` responde a una pregunta de solo lectura sobre una ejecución viva, y `setSearchAttribute()` hace que se pueda encontrar. `#[WaitForAll]`, `#[WaitForAny]`, `#[ChildWorkflow]`, las condiciones de paso, `ContinueAsNew` y los workflows encolados (`triggerMode: TriggerMode::Async`) están en la guía del módulo.

<!-- illustrative: delivering the signal and advancing timers from the command line -->
```bash
php artisan firefly:orchestration:signal 7c1d0e8a-5b4f-4c3e-9a21-0f6e2d4b8c13 email-verified --payload='{"token":"t-1"}'
php artisan firefly:orchestration:tick
```

---

## Un nodo cada vez: el cerrojo del planificador

El tic, el barrido de recuperación y cada `#[ScheduledSaga]`, `#[ScheduledTcc]` y `#[ScheduledWorkflow]` se ejecutan en la planificación del framework exactamente como lo hace una tarea `#[Scheduled]` tuya, cada uno bajo un nombre de cerrojo propio; y todo escritor de una ejecución toma antes un cerrojo por ejecución. En un servidor no hace falta nada más. En tres, tres planificadores disparan el mismo tic en el mismo minuto — así que una tarea protegida con `#[Scheduled(lock: …)]` pide primero un `DistributedLock`, y solo el nodo que gana la ejecuta. `firefly.scheduling.lock.provider` elige el backend:

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

| `firefly.scheduling.lock.provider` | Backend | Funciona en | Notas |
|---|---|---|---|
| `none` (por defecto) | `NoneLock` | cualquier cosa | toda adquisición tiene éxito — correcto para un nodo, sin exclusión entre varios |
| `cache` | `CacheLock` | un store de caché con cerrojos | `database`, `redis` o `memcached` entre nodos; `array` es por proceso |
| `database` | `DatabaseLeaseLock` | cualquier conexión de base de datos de Laravel | una fila de arrendamiento por nombre de cerrojo en `firefly_scheduling_locks`; el TTL se respeta en todos los drivers |
| `mysql` | `MySqlNamedLock` | MySQL, MariaDB | `GET_LOCK`/`RELEASE_LOCK`, retenido por la sesión, sin TTL |
| `postgres` | `PgAdvisoryLock` | PostgreSQL | advisory lock con ámbito de sesión |

`DatabaseLeaseLock` es el que no necesita nada más allá de la base de datos que ya tienes: adquirir es un `UPDATE` condicional que se queda con un arrendamiento vencido, o un `INSERT` cuando la fila no existe (dentro de un savepoint cuando hay una transacción abierta, para que una carrera perdida nunca aborte tu unidad de trabajo); liberar borra la fila solo mientras siga llevando el token de este nodo, así que una tarea que se pasó de su TTL no puede liberar el arrendamiento que otro nodo ha tomado desde entonces. El cerrojo de la orquestación va sobre el mismo proveedor, retenido como mucho `firefly.orchestration.lock.ttl` y esperado como mucho `firefly.orchestration.lock.wait`:

<!-- source: skeleton/config/firefly.php -->
```php
'lock' => [
    'ttl' => '5m',
    'wait' => '5s',
],
```

---

## Eventos, disparadores y operación

- **Eventos del ciclo de vida.** Con `firefly.orchestration.events.enabled`, cada ejecución publica `EXECUTION_STARTED`, `EXECUTION_COMPLETED`, `STEP_COMPLETED` y `STEP_FAILED` a través del `EventPublisher` (Capítulo 8) — un workflow publica sus eventos de paso solo con `publishEvents: true`. Un `#[StepEvent]` en un paso publica la finalización de ese paso en el topic y el tipo que nombra, estén o no activos los eventos del ciclo de vida.
- **Disparadores.** El `triggerEventType` de una definición la arranca desde un evento del bus con el payload del evento como entrada; el correlation id de la ejecución se deriva del id del evento, así que un evento reentregado encuentra su ejecución ya creada y se reconoce como una operación nula.
- **Planificaciones.** `#[ScheduledSaga]`, `#[ScheduledTcc]` y `#[ScheduledWorkflow]` arrancan una definición con una cadencia cron, de ritmo fijo o de retardo fijo, bajo el cerrojo de arriba.
- **Verlo.** `GET /actuator/orchestration` (una vez expuesto) enumera las definiciones con sus capas de la topología, el número de ejecuciones, las cartas muertas y los disparadores; el componente de salud `orchestration` informa del store, de las ejecuciones abiertas y obsoletas y de las cartas muertas; con `firefly/observability`, las ejecuciones y los pasos se miden y se trazan. `firefly:cache` compila cada `#[Saga]`, `#[Tcc]` y `#[Workflow]` en `orchestration.php`, y la topología se valida al arrancar — un ciclo, un `dependsOn` desconocido o una compensación que falta impiden el arranque.

---

## Qué significa el modelo síncrono de PHP

Los pasos de una capa de la topología se ejecutan uno tras otro, en orden de declaración: una capa es la garantía de que cada dependencia terminó antes de que empiece un dependiente, no una promesa de paralelismo. `layerConcurrency`, el `cpuBound` de un paso y el `async` de un paso de workflow se aceptan para que las definiciones se porten sin cambios desde el motor de Java, y no tienen efecto; el endpoint del actuator lo dice en sus `notes`. Para el trabajo que deba salir de la petición, usa un workflow encolado, o arranca la saga desde un evento que consuma un worker.

!!! laravel "Paridad con Laravel"
    `Bus::chain()` de Laravel ejecuta trabajos en orden y se detiene en el primer fallo; `Bus::batch()` los ejecuta juntos y te avisa al final. Ninguno sabe deshacer los trabajos que ya salieron bien, ninguno persiste un punto de control paso a paso desde el que reanudar, y ninguno puede esperar tres días a una señal. `firefly/orchestration` deja la cola de Laravel para lo que hace bien — el trabajo de un workflow ASYNC es un trabajo encolado normal — y añade la compensación, los puntos de control y las esperas duraderas.

---

## Lo que aprendiste {.recap}

| Concepto | Qué hace |
|---|---|
| `#[Saga]` / `#[SagaStep]` | Pasos hacia delante en capas de `dependsOn`, cada uno su propia unidad de trabajo, con `compensate:` nombrando cómo deshacerlo |
| Compensación | Deshace los pasos completados empezando por el más reciente (`strict-sequential`), o bajo otras cuatro políticas; un reembolso, no un rollback |
| `SagaEngine::execute()` / `SagaResult` | Ejecuta una saga en el proceso; `success`, `status`, `firstErrorStepId()`, `compensatedSteps()` |
| TCC | Primero `#[TryMethod]` en todas partes, luego `#[ConfirmMethod]` en todas partes, o `#[CancelMethod]` para cada participante que lo intentó |
| Workflow duradero | `#[WaitForSignal]` / `#[WaitForTimer]` lo aparcan en `WAITING`; una señal o el tic lo reanudan desde su punto de control |
| `firefly.orchestration.persistence.provider` | Puntos de control en `memory`, `cache`, `database` o `event-sourced`; la recuperación informa, reanuda o compensa las ejecuciones obsoletas |
| Cola de cartas muertas | Una entrada por paso fallido; `firefly:orchestration:dlq` las enumera, `firefly:orchestration:dlq:retry` la arranca de nuevo |
| `firefly.scheduling.lock.provider` | `none`, `cache`, `database`, `mysql` o `postgres` — mantiene un tic programado en un solo nodo |

---

## Ponlo en práctica {.exercises}

1. **Cambia la política.** Ejecuta `PayoutSagaTest` con `firefly.orchestration.saga.compensation-policy=best-effort` añadido a `LumenOrchestrationTestCase` y confirma que los tres desenlaces no cambian — luego explica por qué: ninguna compensación de la saga de pagos salientes falla nunca.
2. **Haz que falle una compensación.** En una copia de prueba, haz que `SimulatedBankGateway::cancel()` lance una excepción, vuelve a ejecutar el test del fallo en la confirmación, y lee el estado final, el estado de cada paso en `$result->steps` y `$result->uncompensatedSteps` — una vez con el manejador de errores de compensación por defecto y otra con `firefly.orchestration.saga.compensation-error-handler=fail-fast`. ¿Cuál de los dos reembolsó el monedero, y qué respuesta daría `PayoutController` en cada caso?
3. **Sobrevive a un reinicio.** Copia `OnboardingWorkflow` en una aplicación tuya, apunta `firefly.orchestration.persistence.provider=database` a un servidor real, arranca el workflow en un proceso PHP y entrega `email-verified` desde un segundo proceso con `php artisan firefly:orchestration:signal`.
