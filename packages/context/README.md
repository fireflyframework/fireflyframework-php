# firefly/context

LaraFly's boot engine: the `FireflyKernel` ordered boot pipeline (Spring `ApplicationContext` analog), a two-pass
`ConditionEvaluator` with `#[ConditionalOn*]`, a two-pass `BeanPostProcessor` chain, `#[PostConstruct]`/`#[PreDestroy]`
lifecycle callbacks, an `ApplicationEventPublisher` over Laravel's event dispatcher, and Octane state hygiene.

Apache-2.0 © Firefly Software Solutions Inc.
