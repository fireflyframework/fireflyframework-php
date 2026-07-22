# firefly/domain

LaraFly's DDD base: `Entity` (identity equality), `AggregateRoot` (a pending domain-event buffer),
`ValueObject` (value equality via the `ValueObjectEquality` trait), and `DomainEvent` (uuid / occurredAt /
eventType). The event buffer is also a `RecordsDomainEvents` interface + a `HasDomainEvents` trait, so a persisted
Eloquent `Model` (which cannot extend `AggregateRoot`) can still BE an auto-dispatching aggregate. Pure PHP with
zero framework dependencies and zero reflection — the framework-side wiring (auto after-commit dispatch of pulled
events) lives in `firefly/data`, keeping the domain pure.

Apache-2.0 © Firefly Software Solutions Inc.
