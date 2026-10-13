# firefly/cqrs

LaraFly's CQRS dispatch layer: a synchronous in-process `CommandBus` (`send`) / `QueryBus` (`ask`)
mediator, `#[CommandHandler]` / `#[QueryHandler]` class stereotypes discovered into a compiled
`HandlerManifest` and populated into a `HandlerRegistry` at boot, a bounded pipeline of injectable,
no-op-by-default seams (validation over the shipped `Validator`, authorization, correlation, metrics,
query cache), and the domain->integration-event bridge that re-emits every committed
`Firefly\Domain\DomainEvent` onto the `firefly/eda` `EventPublisher`. `#[Transactional]` handlers get
transaction semantics for free from the firefly/data proxy — cqrs writes no interception code.
With `firefly.cqrs.query.cache.provider=cache` the query cache is real — `#[CachedQuery]` results on the Laravel
cache, evicted by `#[InvalidatesQuery]` events committed in process or arriving over the bus.
Read models / projections are a future `firefly/eventsourcing` package.

Apache-2.0 © Firefly Software Solutions Inc.
