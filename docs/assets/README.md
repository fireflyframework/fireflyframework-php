# Diagrams

The SVGs under `diagrams/` are **hand-authored, static** architecture diagrams — plain `<rect>`/`<line>`/`<text>`
markup with `viewBox` scaling, `font-family="sans-serif"` (no external font/script/CSS dependency, no
renderer such as Mermaid or PlantUML involved). Each one is self-contained and safe to view directly on
GitHub/Packagist, not only through the built MkDocs site.

Palette (theme-neutral — chosen to read on both a light and a dark surrounding page, since the SVG carries
its own white card background rather than inheriting the page's theme): strokes `#4b5563`, process fills
`#eef2ff` / `#ecfdf5`, neutral chip fills `#f3f4f6`, text `#1f2937`.

Each diagram was drawn directly from the shipped source, not invented — the class/file set it depicts is
noted in its own `<desc>` element and below:

| Diagram | Depicts | Verified against |
|---|---|---|
| `boot-pipeline.svg` | `FireflyServiceProvider::register()` buffering `BootPass`es into `PendingBootPasses`, drained at `booting()`/`booted()`, phases run in kernel-decided order | `packages/context/src/Boot/{FireflyServiceProvider.php,PendingBootPasses.php,BootPhase.php}` |
| `di-autoconfig.svg` | PSR-4 scanner → compiled manifest (`var_export`, zero-reflection load) → `ConditionPassTwoPass` (incremental `#[ConditionalOnMissingBean]`/`#[ConditionalOnProperty]` evaluation) → bean registry → eager singletons | `packages/container/src/Scanner/*`, `packages/context/src/{Scanner,Pass}/*`, `packages/autoconfigure/src/*` |
| `request-lifecycle.svg` | Ordered `WebFilter` chain → `ControllerDispatcher` → `ControllerSecurityGuard`/`#[PreAuthorize]` → `#[RestController]` → RFC-7807 rendering on a thrown exception | `packages/web/src/{Filter,Dispatch,Route}/*`, `packages/security/src/Web/MethodSecurityControllerGuard.php` |
| `outbox-flow.svg` | The genuine same-transaction outbox: `OutboxPreCommitHook` INSERTs the `firefly_eda_outbox` row on the aggregate's own connection *inside* its open transaction, atomic commit, then a separate terminal `PostgresEventConsumer` claims `PENDING` rows with `FOR UPDATE SKIP LOCKED` and marks them `PUBLISHED` | `packages/data/src/{Transaction/TransactionTemplate.php,Domain/DomainEventDispatcher.php}`, `packages/eda-postgres/src/{Outbox/OutboxPreCommitHook.php,PostgresEventPublisher.php,PostgresEventConsumer.php}` |
| `cqrs-eda-bridge.svg` | The `CommandBus`/`QueryBus` pipelines plus the domain → integration-event bridge: a guarded wildcard listener on the in-process dispatcher re-emits every committed `DomainEvent` through `EdaCommandEventPublisher` onto the `firefly/eda` `EventPublisher` port | `packages/cqrs/src/{Command/DefaultCommandBus.php,Query/DefaultQueryBus.php,Event/DomainEventBridge.php,Event/EdaCommandEventPublisher.php,Boot/DomainEventBridgeWiringPass.php}` |

Apache-2.0 © Firefly Software Solutions Inc.
