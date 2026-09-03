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

### Where each setting is read from

`ProfileResolver` consults three sources per setting, in this order, and treats a blank or non-scalar value
at any level as absent:

1. **`Illuminate\Support\Env`** — the reader behind Laravel's `env()` helper. It sees `$_ENV`, `$_SERVER`
   and `putenv()` values, so PHPUnit `<env>` entries, `docker --env`, `php-fpm` `env[]` and a parsed `.env`
   all resolve here. A real environment variable is the most specific signal available, so it wins.
2. **The config repository** — `firefly.profiles.active`, then `app.env`. A list is accepted here, because
   `['prod', 'eu']` reads far better in a PHP config file than `'prod,eu'`; both spellings converge.
3. **Raw `getenv()`** — last resort, for a process that called `putenv()` after Env's repository was built,
   or that runs with no Laravel application at all.

Reading `getenv()` *only* — which is what this used to do — collapsed profiles to `['default']` in exactly
the two places they matter most. Under `orchestra/testbench` the environment is set on the config repository
and `putenv()` is never called, so a test asserting that a `#[Profile('test')]` bean is registered watched it
silently not be. And under `php artisan config:cache`, Laravel's `LoadEnvironmentVariables` bootstrapper
returns early, so `.env` is never parsed while the cached repository holds the correct `app.env` the whole
time — profiles switched themselves off in production the moment an app followed the deployment guide.

### `#[Profile]` gating

`#[Profile('prod')]` on a `#[ConfigProperties]` DTO means the DTO is bound **only** when one of the named
profiles is active. Multiple names are OR, never AND:

```php
use Firefly\Config\Attributes\ConfigProperties;
use Firefly\Config\Profile\Profile;

#[Profile('prod', 'staging')]
#[ConfigProperties('payments')]
final readonly class PaymentsProperties
{
    public function __construct(public string $gatewayUrl) {}
}
```

The chain is: `ProfileRequirement` reads the attribute **once, at scan time**; `ConfigPropertiesScanner`
records the result on the descriptor; the compiled `config-properties.php` carries it; and `ConfigRegistrar`
skips the binding when the profiles are not active. Nothing reflects a user class at boot to discover the
gate, and an excluded DTO simply **does not exist** — injecting it fails loudly at resolution time rather
than quietly handing back configuration that was meant to be unreachable.

Until this landed the attribute was pure decoration: it was exported and documented, `grep -rn 'Profile::class'
packages/*/src` matched zero lines of production code, and a class marked `#[Profile('prod')]` was registered
under every profile including the ones the annotation exists to exclude.

**For a non-DTO bean** — anything that is not `#[ConfigProperties]` — use `firefly/context`'s
`#[ConditionalOnProfile]` instead. It is the same predicate, already wired into `ConditionEvaluator`.
Gating a general `#[Component]` with `#[Profile]` additionally needs `firefly/context` to record the
requirement while it scans, and `firefly/config` sits below Context in the layer graph, so it cannot reach
up to do it.

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

### Relaxed binding

A constructor parameter is **not** matched by its exact name alone. Each one is looked up under four
spellings, in this fixed precedence order — the same relaxed binding Spring Boot performs:

| # | Spelling | Example for `$dailyTransferLimitMinor` |
|---|---|---|
| 1 | exact parameter name | `dailyTransferLimitMinor` |
| 2 | `snake_case` | `daily_transfer_limit_minor` |
| 3 | `kebab-case` | `daily-transfer-limit-minor` |
| 4 | `SCREAMING_SNAKE_CASE` | `DAILY_TRANSFER_LIMIT_MINOR` |

The order depends only on the parameter name, never on the iteration order of the config array, so binding
stays deterministic even when an array carries two spellings of the same property at once. Duplicate
spellings collapse — a parameter already written in snake_case yields two candidates, not four.

This exists because the two worlds otherwise never met. A `config/*.php` file is written by hand in whatever
casing the application's house style prefers, and its values very often arrive from environment variables,
which are `SCREAMING_SNAKE` by convention; a PHP constructor parameter is camelCase because PSR-12 says so.
Matching only the exact name meant `'daily_transfer_limit_minor' => 250000` bound **nothing** onto
`public int $dailyTransferLimitMinor` — and since an unmatched parameter with a default is not an error, the
DTO came out holding the default. No exception, no log line, no failing test, just a wrong limit in
production. This repo's own book shipped exactly that example, which is how the defect was caught.

Two details worth knowing:

- **Acronyms survive.** The camelCase → snake_case step breaks a lower-or-digit → upper boundary *and* an
  acronym running into a following word, so `$apiURL` becomes `api_url` and `$HTTPProxyHost` becomes
  `http_proxy_host` — not `api_u_r_l` and `_h_t_t_p_proxy_host`, which nobody would ever type into a config
  file.
- **A present-but-null key does not stop the search.** `'port' => env('MAIL_PORT')` yields `null` when the
  variable is unset — the ubiquitous Laravel idiom — so a `null` under the exact name must not mask a real
  value written in snake_case. It is read as "not supplied", exactly as `Config::required()` reads it.

A parameter with no matching key, no default and no nullable type throws a `ConfigurationException` naming
the property, the class, and every key that was tried.

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
