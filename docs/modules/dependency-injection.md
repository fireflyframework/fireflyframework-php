# Dependency Injection

`firefly/container` is LaraFly's dependency-injection layer over `Illuminate\Container`. You annotate classes
with PHP 8 attributes; a component scan compiles them to a cached manifest; the container resolves them by
type, by interface, by name, or as ordered lists.

## Stereotypes

Mark a class as a managed component with a stereotype attribute:

```php
use Firefly\Container\Attributes\Service;

#[Service]
final class OrderService {}
```

`#[Service]`, `#[Repository]`, and `#[Configuration]` are all specialisations of `#[Component]`; a scan finds
them via `ReflectionClass::getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF)`.

## Scopes

Every component is a singleton by default. Choose another lifetime with the `scope` argument:

```php
use Firefly\Container\Attributes\Service;
use Firefly\Container\Scope;

#[Service(scope: Scope::Transient)]  // a new instance per resolution
final class RequestId {}
```

`Scope::Scoped` binds one instance per Laravel scope/request. (`Session` scope arrives with `firefly/session`.)

## Interfaces, `#[Primary]`, and `#[Qualifier]`

When several components implement one interface, the container binds the interface to the `#[Primary]` one, and
you can fetch any specific implementation by name:

```php
use Firefly\Container\Attributes\{Primary, Qualifier, Service};

interface Greeter { public function greet(): string; }

#[Service] #[Primary]
final class EnglishGreeter implements Greeter { public function greet(): string { return 'Hello'; } }

#[Service('spanish')] #[Qualifier('spanish')]
final class SpanishGreeter implements Greeter { public function greet(): string { return 'Hola'; } }
```

```php
$container->get(Greeter::class);         // EnglishGreeter (primary)
$container->getByName('spanish');        // SpanishGreeter
$container->getAll(Greeter::class);      // all implementations, sorted by #[Order]
```

## `#[Order]`

`#[Order]` sets list precedence (lower first, Spring convention). `getAll()` returns implementations sorted by it.

## `#[Lazy]` (reserved)

`#[Lazy]` is accepted on components for forward-compatibility but is currently a **no-op**: `Illuminate\Container`
already resolves bindings lazily by default, so there is nothing extra to defer today. Explicit-lazy semantics
(e.g. proxying construction) are reserved for a future milestone.

## `#[Bean]` factory methods

A `#[Configuration]` class exposes `#[Bean]` methods; each is registered under its return type, with parameters
injected:

```php
use Firefly\Container\Attributes\{Bean, Configuration};

#[Configuration]
final class AppConfig
{
    #[Bean('utcClock')]
    public function clock(): Clock { return new Clock('UTC'); }
}
```

## `#[Value]` injection

Inject configuration and expressions into constructor parameters:

```php
use Firefly\Container\Attributes\Value;

final class MailerConfig
{
    public function __construct(
        #[Value('${MAIL_HOST:localhost}')] public readonly string $host,
        #[Value('#{25 * 2}')] public readonly int $port,
    ) {}
}
```

`${NAME:default}` reads an environment variable (falling back to the default); `#{expr}` evaluates a sandboxed
expression. `firefly/config` extends resolution to read application configuration.

## Caching (Octane-safe)

The component scan is compiled once to a manifest and never runs per request. At runtime the container is booted
from the frozen manifest — no reflection on the hot path — which keeps it fast and safe under Octane.
