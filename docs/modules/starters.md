# Auto-Configuration & Starters

LaraFly boots like Spring Boot: install a capability package and its sensible defaults wire themselves up,
while your own beans always take precedence. This is the job of `firefly/autoconfigure`.

## How it works

1. **Candidacy (register time).** Each capability package ships a discovered provider extending
   `Firefly\AutoConfigure\AutoConfiguration`. Its `register()` records only its *candidacy* (the compiled
   manifests describing its `#[Configuration]` classes) into a container-bound `AutoConfigurationCollector`. It
   binds nothing and never touches the kernel, so the order Laravel happens to register providers in is
   irrelevant.
2. **Discovery (phase 200).** On the kernel's clock, `AutoConfigDiscoveryPass` loads every candidate's compiled
   manifests and *assembles* `BeanDefinition`s — it writes nothing to the registry.
3. **Commit (phase 500).** `AutoConfigurationsPass` drains those definitions into the registry, tagged
   `DefinitionSource::AutoConfiguration`.
4. **Back-off (phase 600).** The M4 `ConditionPassTwoPass` evaluates each auto-config definition incrementally,
   sorted by `(#[Order], FQCN)`, against a registry that already holds every surviving user bean. A
   `#[ConditionalOnMissingBean(X)]` default therefore backs off exactly when *you* supply `X`, and two starters
   competing for one port resolve first-wins — never both-dropped.

## Writing an auto-configuration

A `#[Configuration]` class with `#[Order(1000)]` (so it runs after user configuration) and a gated `#[Bean]`:

```php
#[Configuration]
#[Order(1000)]
final class CacheAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(CachePort::class)]
    public function defaultCache(): CachePort
    {
        return new InMemoryCache;
    }
}
```

Relative ordering (`#[AutoConfigureBefore/After]`) is not yet supported — use numeric `#[Order]`.

## Booting an app

The auto-discovered `FireflyAutoConfigureServiceProvider` binds the kernel and scans your app by convention from
`config('firefly.scan.paths')` (a PSR-4 map). Compiled app manifests, when configured via
`firefly.cache.component_manifest` / `firefly.cache.context_manifest`, are loaded reflection-free; otherwise the
roots are scanned in-process in development.
