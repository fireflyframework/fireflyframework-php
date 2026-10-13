# firefly/orchestration

LaraFly's distributed-transaction patterns, shaped after the Java `fireflyframework-orchestration` engine:
**sagas** (forward steps with compensations, five compensation policies, error handlers, a builder DSL and
saga composition), **TCC** (ordered try/confirm/cancel participants with per-phase timeouts and retries) and a
**durable workflow** engine (dependencies, ExpressionLanguage conditions, signals, timers, wait-for-all/any,
child workflows, continue-as-new, queries, suspend/resume and search attributes, triggered synchronously or
through the Laravel queue).

Definitions are discovered from attributes (`#[Saga]`, `#[Tcc]`, `#[Workflow]`, …) and compiled by
`php artisan firefly:cache`; their topology is validated at boot. Execution state is checkpointed at every
step through the `ExecutionPersistenceProvider` port — `memory` (default), `cache` or `database`
(`firefly.orchestration.persistence.provider`) — so a recovery sweep can resume or compensate an execution a
crashed process left behind. Failed executions are parked in a dead-letter queue (`memory` or `database`),
lifecycle events publish through `firefly/eda`, the `orchestration` actuator endpoint and health component
report what is running, and `php artisan firefly:orchestration:*` lists, shows, signals, ticks and retries.

The guide is [docs/modules/orchestration.md](../../docs/modules/orchestration.md).

Apache-2.0 © Firefly Software Solutions Inc.
