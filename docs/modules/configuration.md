# Configuration

`firefly/config` layers Spring-style configuration onto Laravel's config repository: active **profiles**, a
typed **config accessor**, `#[ConfigProperties]` **DTO binding**, and a config-backed **`ValueResolver`** so
`#[Value('${…}')]` reads real application configuration.

## Profiles

Active profiles come from `FIREFLY_PROFILES_ACTIVE` (comma-separated), falling back to `APP_ENV`, then
`default`:

```php
use Firefly\Config\Profile\ProfileResolver;

$profiles = (new ProfileResolver())->resolve();
$profiles->isActive('prod');   // bool
$profiles->all();              // list<string>
```

`#[Profile('prod')]` marks a component as active only under a given profile (enforced by conditional
registration in a later milestone).

## Typed access

`Config` wraps the repository with fail-fast typed getters — a missing required key or a type mismatch throws
`ConfigurationException` instead of returning `null`:

```php
$config->string('mail.host');          // required — throws if absent
$config->int('mail.port', 25);         // default when absent
$config->bool('mail.tls', false);
$config->array('mail.recipients', []);
```

## `#[ConfigProperties]` binding

Bind a config subtree onto a plain readonly DTO:

```php
use Firefly\Config\Attributes\ConfigProperties;

#[ConfigProperties('mail')]
final readonly class MailProperties
{
    public function __construct(
        public string $host,
        public int $port = 25,
        public bool $tls = false,
    ) {}
}
```

The DTO is registered as a container singleton bound from `config('mail')`, so it can be injected wherever
`MailProperties` is requested. Nested object-typed constructor parameters bind recursively from their
sub-arrays. Discovery compiles to a cached manifest (Octane-safe). The binder sits behind a `ConfigBinder`
seam, so a richer binder can be swapped in without touching your DTOs.

## `#[Value]` from config

With `firefly/config` installed, `#[Value]` injection resolves against config first, then the environment,
then the default:

```php
final class Mailer
{
    public function __construct(
        #[Value('${mail.host:localhost}')] public readonly string $host,
    ) {}
}
```

`firefly/config` binds its `ConfigValueResolver` over `firefly/container`'s default resolver; because the
container registrar only registers its default *if none is bound*, config-backed resolution wins.
