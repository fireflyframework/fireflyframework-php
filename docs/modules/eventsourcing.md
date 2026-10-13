# Event Sourcing

`firefly/eventsourcing` stores what happened instead of what is. An aggregate's state is the replay of its
events. The events live in an `EventStore` (`memory` or `database`, `firefly.eventsourcing.store.provider`),
appended under optimistic concurrency and read back two ways:

- as the aggregate's own stream;
- as one global stream in commit order, which projections follow with a checkpoint.

Snapshots shorten replays, upcasters move old event versions forward on read, and appended events can be
published to `firefly/eda` in the same transaction through the database outbox. The package is shaped after
the Java `fireflyframework-eventsourcing` library and PyFly's `pyfly.eventsourcing`.
