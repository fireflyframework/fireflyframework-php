# firefly/autoconfigure

LaraFly's auto-configuration engine: an `AutoConfiguration` provider base that records candidacy into a
container-bound `AutoConfigurationCollector`, a `DefinitionAssembler` that joins M2/M4 compiled manifests into
`BeanDefinition`s, the `AutoConfigDiscoveryPass` (phase 200) / `AutoConfigurationsPass` (phase 500) seam passes,
and the `FireflyAutoConfigureServiceProvider` bootstrap that binds the `FireflyKernel` and contributes the boot
pipeline — Spring Boot's `@EnableAutoConfiguration`, native to Laravel.

Apache-2.0 © Firefly Software Solutions Inc.
