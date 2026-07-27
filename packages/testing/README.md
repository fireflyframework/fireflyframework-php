# firefly/testing

LaraFly's dev-scoped first-party test kit — the `spring-boot-test`/`@SpringBootTest` analog every other
package in the monorepo dogfoods for its own suite. A boot harness (`FireflyTestCase` +
`bootFireflyApp()`/`fireflyApplication()`, mirroring the real AutoConfigure-first production boot path)
and `FireflyDatabaseTestCase`/`UsesSqliteMemory` for database-backed tests; web/data "slice" bases plus
`#[FireflyTest]`/`#[WebSlice]`/`#[DataSlice]` attributes that boot only the beans a test needs; recording
doubles for every one of Firefly's own ports (`RecordingEventPublisher`, `RecordingCommandBus`,
`RecordingMessageBroker`, `RecordingTracer`, `FakeHealthIndicator`, and more); Firefly-flavored Pest
expectations (`toHavePublished`/`toHaveHandledCommand`/`toBeUp`/`toHaveRecordedMetric`/
`toBeProblemDetails`); a fixture layer (`FixtureRegistry`/`AggregateSeeder`/`ListenerSpy`); and a
`RequiresDocker` testcontainers hook for `@group('integration')` tests. `require-dev` only, no runtime
service provider.

See [Testing](../../docs/modules/testing.md) for the full reference.

Apache-2.0 © Firefly Software Solutions Inc.
