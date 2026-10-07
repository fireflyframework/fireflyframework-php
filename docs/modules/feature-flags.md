# Feature Flags

`firefly/feature-flags` is LaraFly's feature-flag subsystem, built on [OpenFeature](https://openfeature.dev): flags are
[flagd](https://flagd.dev) flag definitions, evaluated in-process by a PHP port of flagd's evaluator, and an
application gates code with an attribute, a route middleware, a Blade directive or a facade call. Operators change
flags at runtime from the [admin dashboard](admin.md), the `flags` actuator endpoint or `php artisan firefly:flags`,
with an audit trail, and every PHP-FPM worker sees the change within the source's refresh interval.

The same flag document evaluates to the same value, variant and reason in LaraFly and in PyFly, and a user lands in
the same percentage bucket in both: the two frameworks share [one contract](../feature-flags-contract.md) and run the
same conformance files in their test suites.

The checked-in [Lumen sample](https://github.com/fireflyframework/fireflyframework-php/tree/main/samples/lumen) exercises the facade, a gated route, shared targeting
vectors, test overrides, store transactions and preview exposure behavior. Both editions of *LaraFly by Example*
develop that sample in Chapter 13A.

## Quick start

Switch the subsystem on and declare flags in `config/firefly.php`:

<!-- illustrative: an application's own configuration file -->
```php
return [
    'feature-flags' => [
        'enabled' => true,
        'flags' => [
            'new-checkout' => false,
            'checkout-flow' => 'v2',
        ],
    ],
];
```

`true` and `false` are shorthand for a boolean flag with the variants `on` and `off`; a string is shorthand for a
flag with that single variant. Then ask for a flag wherever you need it:

<!-- illustrative: an application's own service -->
```php
use Firefly\FeatureFlags\FeatureFlags;

final class CheckoutPage
{
    public function __construct(private readonly FeatureFlags $flags) {}

    public function template(): string
    {
        if (! $this->flags->isEnabled('new-checkout')) {
            return 'checkout.legacy';
        }

        return $this->flags->getString('checkout-flow', 'v1') === 'v2' ? 'checkout.v2' : 'checkout.v1';
    }
}
```

`FeatureFlags` has a typed getter per flag type — `isEnabled()`, `getString()`, `getInt()`, `getFloat()`,
`getObject()` — plus `variant()` and `details()`, which returns the value, the variant, the reason, the error code
and the flag's metadata. Every getter takes the default to answer when the flag is missing or fails, and an optional
evaluation context and targeting key. Under the facade sits a named OpenFeature client (`Client` is a bean too), so
code written against the OpenFeature PHP SDK works unchanged.

## Defining flags

A flag is a flagd flag definition: a `state`, the `variants` it can answer, the `defaultVariant`, optional
`targeting` rules in JSON Logic, and optional `metadata`.

<!-- illustrative: a flag document an application writes; it is data, not code in this repository -->
```json
{
  "flags": {
    "new-checkout": {
      "state": "ENABLED",
      "variants": {"on": true, "off": false},
      "defaultVariant": "off",
      "targeting": {"if": [{"in": ["beta", {"var": "roles"}]}, "on", null]},
      "metadata": {"owner": "payments", "kind": "release", "expires": "2026-12-31"}
    }
  },
  "$evaluators": {"is-beta": {"in": ["beta", {"var": "roles"}]}}
}
```

- **Keys** match `^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$`; they appear in URLs and CLI arguments.
- **Variants** all hold one JSON type: boolean, string, number, or object.
- **Targeting** returns a variant name, or `null` to fall back to `defaultVariant`. The operators are flagd's:
  JSON Logic plus `fractional`, `sem_ver`, `starts_with` and `ends_with`; `{"$ref": "is-beta"}` reuses a shared rule
  from `$evaluators`.
- **Metadata** values are scalars. Four keys are reserved: `description`, `owner`, `kind` (`release`, `experiment`,
  `ops` or `permission`) and `expires` (`YYYY-MM-DD`). A flag past its `expires` date still evaluates; it is flagged
  as expired in the admin page, the actuator, the health details, and one warning per day in the log.
- A field flagd does not define is kept as written and ignored.

An invalid definition in `flags` or in a flag file refuses the boot, naming the key and the reason (for example
`Invalid feature flag [bad key] from source [config]: invalid flag key.`); an invalid document from the sync
endpoint or the store is rejected as a whole and the last good one kept.
After a file has loaded successfully, a later invalid edit also leaves its last good document in force. Replace a
file atomically (write a sibling file, then rename it) so a polling worker never parses a partial write. Quote YAML
date-like keys and values: YAML parsers otherwise disagree about dates and non-string keys that JSON cannot express.

## Targeting recipes

Every recipe is a flag definition you can put in `flags`, a flag file or the store.

**Release toggle and kill switch.** A boolean flag, off by default; turning it on (or a `DISABLED` state, which
makes every gate answer its default) needs no deploy once the store is enabled.

<!-- illustrative: a flag definition an application writes -->
```json
{"state": "ENABLED", "variants": {"on": true, "off": false}, "defaultVariant": "off"}
```

**Progressive rollout.** `fractional` hashes the flag key and the targeting key (the signed-in principal's name),
so a user stays in their bucket as the percentages move, in PHP and in Python alike. Anonymous traffic has no
targeting key and gets the default variant.

<!-- illustrative: a flag definition an application writes -->
```json
{"state": "ENABLED", "variants": {"on": true, "off": false}, "defaultVariant": "off",
 "targeting": {"fractional": [["on", 10], ["off", 90]]}}
```

**Beta cohort.** `roles` holds the principal's `ROLE_` authorities without the prefix.

<!-- illustrative: a flag definition an application writes -->
```json
{"state": "ENABLED", "variants": {"on": true, "off": false}, "defaultVariant": "off",
 "targeting": {"if": [{"in": ["BETA", {"var": "roles"}]}, "on", null]}}
```

**Per-tenant or per-plan entitlement.** `tenant` comes from the principal attribute named by
`firefly.feature-flags.context.tenant-attribute`; `plan` comes from a context contributor of your own (below).

<!-- illustrative: a flag definition an application writes -->
```json
{"state": "ENABLED", "variants": {"on": true, "off": false}, "defaultVariant": "off",
 "targeting": {"if": [{"or": [{"in": [{"var": "tenant"}, ["acme", "globex"]]}, {"==": [{"var": "plan"}, "enterprise"]}]}, "on", null]}}
```

**Multivariate experiment.** A weighted split with a holdout; switch `firefly.feature-flags.events.evaluations` on to
publish a `FeatureFlagEvaluated` event per evaluation — the exposure record the experiment's analysis needs.

<!-- illustrative: a flag definition an application writes -->
```json
{"state": "ENABLED", "variants": {"control": "v1", "treatment": "v2", "holdout": "v1"}, "defaultVariant": "control",
 "targeting": {"fractional": [["control", 45], ["treatment", 45], ["holdout", 10]]},
 "metadata": {"kind": "experiment", "owner": "growth"}}
```

## Gating code

**On a bean.** `#[FeatureFlag]` on a method (or a class: every public method) of any stereotyped bean. While the
flag is off, the call answers the `fallback` method — called with the same arguments — or throws
`FeatureFlagDisabledException`. `variant:` gates on one variant; `default: true` opens the gate when the flag is
missing.

<!-- source: samples/lumen/src/Application/WalletRolloutGate.php -->
```php
<?php

declare(strict_types=1);

namespace Lumen\Application;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[Service]
class WalletRolloutGate
{
    #[FeatureFlag('wallet-premium-label', fallback: 'legacyLabel')]
    public function label(string $name): string
    {
        return "Welcome back, {$name}";
    }

    public function legacyLabel(string $name): string
    {
        return "Hello {$name}";
    }
}
```

The attribute rides the proxy every advice shares, at advice order 80: inside the `#[Timed]` metric (50), so a
refused call is still measured, and outside method security (100), resilience (200) and the transaction (1000), so a
dark feature never spends an authorization check, a retry or a transaction. `php artisan firefly:cache` compiles it
like every other advice. The class must not be `final`; the scanner refuses a placement no proxy could enforce
(a final class, a static method, a fallback that does not exist) with a sentence naming the method.

**On a route.** On a controller action the attribute becomes route middleware, so a dark endpoint answers before
its body is bound or validated:

<!-- source: samples/lumen/src/Web/WalletRolloutController.php -->
```php
<?php

declare(strict_types=1);

namespace Lumen\Web;

use Firefly\FeatureFlags\Gating\FeatureFlag;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;

#[RestController]
class WalletRolloutController
{
    /** @return array{label: string} */
    #[FeatureFlag('wallet-rollout-route')]
    #[GetMapping('/api/v1/wallet-rollout')]
    public function show(): array
    {
        return ['label' => 'Available balance'];
    }
}
```

Routes declared in route files use the same middleware by its alias, `feature-flag:{key}[,{variant}]`:


A gated route that is off answers `firefly.feature-flags.web.disabled-status` — 404 (the default: a dark launch looks
like a page that does not exist), 403 or 503 — as problem+json, and never names the flag.

**In a template.**

<!-- illustrative: an application's own Blade view -->
```blade
@featureflag('new-checkout')
    @include('checkout.v2')
@else
    @include('checkout.legacy')
@endfeatureflag

@featurevariant('checkout-flow', 'v2')
    <p>Try the faster checkout.</p>
@endfeaturevariant
```

Every gate fails **closed** on a missing flag, an evaluation failure, a type mismatch or a switched-off subsystem:
it answers the gate's default, which is "off" unless the gate says `default: true`. A source failure after a
successful load retains the last-good document, so a previously enabled gate can remain open during an outage.
A flag never decides which beans exist:
bean conditions are evaluated once at boot, so a boot-time switch is a property — use `#[ConditionalOnProperty]`.

## Evaluation context

The framework builds a context for every evaluation, and the caller's own attributes win over it:

| Attribute | Value |
|---|---|
| `targetingKey` | the authenticated principal's name; absent when anonymous |
| `roles` | the principal's `ROLE_` authorities, without the prefix |
| `tenant` | the principal attribute named by `firefly.feature-flags.context.tenant-attribute` |
| `application` | `app.name` |
| `profiles` | the active Firefly profiles |

Add your own attributes with a context contributor — any `#[Component]` implementing
`EvaluationContextContributor`; `#[Order]` sets the order, and a later contributor may override an earlier one:

<!-- illustrative: an application's own contributor -->
```php
use Firefly\Container\Attributes\Component;
use Firefly\FeatureFlags\Context\EvaluationContextBuilder;
use Firefly\FeatureFlags\Context\EvaluationContextContributor;

#[Component]
final class PlanContributor implements EvaluationContextContributor
{
    public function __construct(private readonly Subscriptions $subscriptions) {}

    public function contribute(EvaluationContextBuilder $context): void
    {
        $customer = $context->targetingKey();
        if ($customer !== null) {
            $context->set('plan', $this->subscriptions->planOf($customer));
        }
    }
}
```

An explicit context overlays all contributors. `targetingKey` in JSON context accepts a nonempty string or an
integer converted to decimal text; a separate nonempty targeting-key argument wins over it. LaraFly refuses an
Eloquent model, `Arrayable` or `Jsonable` object as a targeting key: pass the user's stable id. It drops unsupported
attribute values and numeric-looking attribute names with a DEBUG diagnostic. A `DateTimeInterface` value becomes
UTC epoch milliseconds, as in PyFly.

## Sources and precedence

Flags come from up to four sources, lowest precedence first: **config** (`flags` and `evaluators`) → a watched
**file** → another service's **http** sync endpoint → the writable **store**. The highest source that defines a key
supplies its whole definition; the admin page and the actuator show which source won (`origin`) and which it
shadows (`overrides`).

<!-- illustrative: an application's own configuration file -->
```php
return [
    'feature-flags' => [
        'enabled' => true,
        'sources' => [
            'file' => ['enabled' => true, 'path' => base_path('flags.yaml'), 'refresh-interval' => '5s'],
            'http' => ['enabled' => true, 'url' => 'https://control-plane.internal/feature-flags/flagd.json', 'token' => env('FLAGS_TOKEN'), 'refresh-interval' => '30s', 'timeout' => '2s'],
            'store' => ['enabled' => true, 'driver' => 'database', 'connection' => null, 'refresh-interval' => '5s'],
        ],
    ],
];
```

PHP-FPM shares nothing between requests, so the registry keeps each source's last good document and its revision
in the application cache. A request re-checks a source only when its `refresh-interval` has elapsed — a file's
modification time and size, the store's newest change id, an HTTP conditional GET under a non-blocking lock (one
worker fetches; the others serve the last good document meanwhile) — and the request that sees a change publishes
`FeatureFlagsChanged`. A long-lived process (Octane, a queue worker) re-checks on the same schedule. When the cache
store is down, sources are read in-process and flags keep evaluating.

Give each application its own application-cache prefix and retain source entries without eviction; a fresh worker
needs those entries to recover the last good file or remote document. A file revision uses whole-second mtime and
size: same-size edits within the same timestamp can remain unseen indefinitely, even after atomic rename. Publish
atomically **and** advance the file's mtime to a distinct second (or change its size); do not rely on rename alone.

Change events are best effort, not durable or exactly once. Rolling workers with different configuration can
alternate shared change announcements. Cache outages suspend shared announcements; recovery with no composed
record reports `startup`. Use the store audit history when you need a durable record of operator writes.

## The store

The store is the writable layer behind the admin page, the actuator endpoint and the CLI. A write replaces the
key's definition for every lower source until it is deleted; every write also appends a change row (who, what,
when), and a write may carry `expectedVersion` so two operators cannot overwrite each other.
The relational tables keep exact-case keys, JSON payloads and zoneless UTC microsecond timestamps across SQLite,
PostgreSQL, MySQL and MariaDB. A write inside the caller's transaction remains pending until that caller commits;
rollback removes both the definition and audit row. If MariaDB aborts an existing snapshot after a competing insert,
the caller must roll back and retry its complete operation. A post-commit local refresh failure preserves the
committed write and the last good local composition.

With the `database` driver, publish and run the migration:

<!-- illustrative: commands an application developer runs -->
```bash
php artisan vendor:publish --tag=firefly-feature-flags-migrations
php artisan migrate
```

The migration is also loaded automatically while the store is enabled with the `database` driver. It creates
`firefly_feature_flags` and `firefly_feature_flag_changes`, the same tables PyFly creates: two services that share
a database share their flags, and either framework reads the rows the other wrote. `connection` picks the Laravel
connection (null is the default one). The `memory` driver keeps flags per process, for tests and demos.

## Managing flags

**Actuator.** `GET /actuator/flags` lists the provider, the sources and every flag; `GET /actuator/flags/{key}`
describes one, with each source's definition and the latest 50 changes; `POST /actuator/flags/{key}` evaluates
(`{"action": "evaluate", "context": {…}, "targetingKey": "…"}`) or writes (`enable`, `disable`,
`default-variant`, `put`, `delete`). The answers are the same JSON PyFly answers, and a refusal is
`{"error": code, "message": text}` with a status per code: `writes-disabled` 403, `not-writable` 409,
`invalid-definition` 422, `unknown-flag` 404, `unknown-variant` 422, `conflict` 409, `bad-request` 400.

The sensitive `/actuator/flags` endpoint answers 404 until `flags` is named in `firefly.management.endpoints.web.exposure.include`, and
writes additionally need `firefly.feature-flags.management.writes` and a store:

<!-- illustrative: an application's own configuration file -->
```php
return [
    'management' => [
        'endpoints' => ['web' => ['exposure' => ['include' => 'health,info,flags']]],
    ],
    'feature-flags' => [
        'enabled' => true,
        'sources' => ['store' => ['enabled' => true]],
        'management' => ['writes' => true],
    ],
];
```

<!-- illustrative: requests an operator sends to a running application -->
```bash
curl -s http://localhost:8000/actuator/flags
curl -s -X POST http://localhost:8000/actuator/flags/new-checkout -H 'Content-Type: application/json' -d '{"action":"enable"}'
curl -s -X POST http://localhost:8000/actuator/flags/new-checkout -H 'Content-Type: application/json' -d '{"action":"evaluate","targetingKey":"ada"}'
```

The actor recorded for a write is the authenticated principal's name, or `actuator` when there is none. An
`evaluate` is a preview: it uses the context you send plus the application's own attributes — never yours — and
counts in no metric and no exposure record.
After a successful `put`, `{"key":"…","refreshPending":true}` means no new local definition is visible yet;
it does not claim a caller-owned transaction has committed. If an older last-good definition remains visible, the
response describes that current view. Poll the detail endpoint or wait for the source refresh before assuming the
local process has adopted the new revision.

**Admin.** The [admin dashboard](admin.md)'s **Feature flags** page lists every flag with its state, origin and
expiry, toggles one, edits its definition as JSON, sets its default variant, previews an evaluation and shows its
history. Its writes follow the same rules and record the actor `admin` when nobody is signed in.

**CLI.**

<!-- illustrative: commands an operator runs on a host of the application -->
```bash
php artisan firefly:flags list
php artisan firefly:flags show new-checkout --json
php artisan firefly:flags evaluate checkout-flow --context='{"plan":"pro"}' --targeting-key=ada
php artisan firefly:flags enable new-checkout --expected-version=0
php artisan firefly:flags disable new-checkout --expected-version=1
php artisan firefly:flags default-variant checkout-flow v2
php artisan firefly:flags put banner --file=banner.json
php artisan firefly:flags delete banner
```

A command-line write records `cli:<os-user>`.
For `evaluate`, omit `--context` or pass `--context='{}'` when there are no explicit attributes. If supplied,
`--context` must contain a JSON object; empty text, JSON null, arrays, scalars and malformed JSON fail with
`bad-request` and exit 1 before any evaluation. Add `--json` to receive the portable error body.

## The sync server

One service can serve its effective flag set to others — a control plane serving every service of a product, in
either framework. The server answers `GET` on its path with the composed flagd document, an `ETag`, and `304` for a
matching `If-None-Match`; it requires `Authorization: Bearer <token>`, and enabling it without a token refuses the
boot unless `allow-anonymous` is set.

<!-- illustrative: the serving application's configuration file -->
```php
return [
    'feature-flags' => [
        'enabled' => true,
        'server' => ['enabled' => true, 'path' => '/feature-flags/flagd.json', 'token' => env('FLAGS_SERVER_TOKEN')],
    ],
];
```

A consumer points its `http` source at that URL with the same token (see [Sources](#sources-and-precedence)). Test
overrides are never served.

## Observability

- **Metric:** `feature_flag_evaluations_total{flag, variant, reason}` on the meter registry when
  [Observability](observability.md) is installed and `firefly.observability.metrics.enabled` is on (the default).
  When metrics are disabled or no meter is available, feature flags use `NoOpFeatureFlagMetrics`; evaluation still
  works but no counter is recorded. `variant` is `none` when there is none, and a failed evaluation counts with
  `reason="ERROR"` when the meter is active.
- **Health:** the `featureflags` component is UP while every source has loaded at least once, DOWN while one never
  has, and lists each source's status, the flag count and the expired flags.
- **Events:** `FeatureFlagsChanged(changedKeys, origin)` when the effective set changes, `FeatureFlagUpdated` after a
  committed store write, and — with `firefly.feature-flags.events.evaluations` — `FeatureFlagEvaluated` per
  evaluation.

<!-- illustrative: an application's own exposure listener -->
```php
use Firefly\FeatureFlags\Event\FeatureFlagEvaluated;
use Illuminate\Support\Facades\Event;

Event::listen(FeatureFlagEvaluated::class, function (FeatureFlagEvaluated $exposure): void {
    Exposures::record($exposure->key, $exposure->variant, $exposure->targetingKey);
});
```

Telemetry never changes an evaluation: a failing meter or listener is logged at debug and the flag's value stands.
When an exposure value contains more than 10,000 value occurrences, the exposure event is omitted, while the
evaluation result and metric remain. The budget counts the root and every nested container or scalar, including
repeated references; a cycle also exhausts the budget.

An application may install a different OpenFeature provider for evaluation. Firefly's registry, source history,
writable store and management operations then have no Firefly document to operate on; keep operator expectations
limited to that provider's own capabilities. The PHP SDK does not expose Firefly's flag metadata, so use
`FeatureFlags::details()` when metadata matters.

## Testing

`withFeatureFlags()` overrides flags of the running application for the rest of a test, above every source,
shorthand included; see [Testing](testing.md#feature-flag-overrides).

<!-- illustrative: an application's own Pest test -->
```php
it('shows the faster checkout to the treatment group', function (): void {
    withFeatureFlags(['new-checkout' => true, 'checkout-flow' => 'v2']);

    $this->get('/checkout')->assertSee('Try the faster checkout.');
});
```

## Configuration

Every key, as the configuration reference documents it:

<!-- source: skeleton/config/firefly.php -->
```php
'feature-flags' => [
    'enabled' => env('FIREFLY_FEATURE_FLAGS_ENABLED', false),

    // 'flags' => [
    //     'new-checkout' => false,
    //     'checkout-flow' => [
    //         'state' => 'ENABLED',
    //         'variants' => ['control' => 'v1', 'treatment' => 'v2'],
    //         'defaultVariant' => 'control',
    //         'targeting' => ['fractional' => [['control', 50], ['treatment', 50]]],
    //         'metadata' => ['owner' => 'payments', 'kind' => 'experiment', 'expires' => '2099-12-31'],
    //     ],
    // ],
    // 'evaluators' => [
    //     'is-beta' => ['in' => ['beta', ['var' => 'roles']]],
    // ],

    // …
    // 'sources' => [
    //     'file' => ['enabled' => false, 'path' => '', 'refresh-interval' => '5s'],
    //     'http' => ['enabled' => false, 'url' => '', 'token' => env('FIREFLY_FEATURE_FLAGS_HTTP_TOKEN', ''), 'refresh-interval' => '30s', 'timeout' => '2s'],
    //     'store' => ['enabled' => false, 'driver' => 'database', 'connection' => null, 'refresh-interval' => '5s'],
    // ],

    // The principal attribute that holds the tenant id (the `tenant` evaluation-context attribute).
    // 'context' => ['tenant-attribute' => 'tenant'],

    // Status of a #[FeatureFlag]-gated route or method when the flag is off: 404, 403 or 503.
    // 'web' => ['disabled-status' => 404],

    // Publish a FeatureFlagEvaluated event per evaluation (exposure records for experiments).
    // 'events' => ['evaluations' => false],

    // Allow writes from the admin page, POST /actuator/flags/{key} and php artisan firefly:flags.
    // 'management' => ['writes' => false],

    // Serve this application's effective flag set to other services (bearer token required unless
    // allow-anonymous is true; enabling it without a token refuses the boot).
    // 'server' => ['enabled' => false, 'path' => '/feature-flags/flagd.json', 'token' => env('FIREFLY_FEATURE_FLAGS_SERVER_TOKEN', ''), 'allow-anonymous' => false],
],
```

| Key (under `firefly.feature-flags`) | Default | Meaning |
|---|---|---|
| `enabled` | `false` | the master switch |
| `flags` | `[]` | inline definitions, flagd or shorthand |
| `evaluators` | `[]` | shared `$evaluators` rules |
| `sources.file.enabled` / `.path` / `.refresh-interval` | `false` / `''` / `5s` | a watched flagd document (`.json`, `.yaml`, `.yml`) |
| `sources.http.enabled` / `.url` / `.token` / `.refresh-interval` / `.timeout` | `false` / `''` / `''` / `30s` / `2s` | the sync client |
| `sources.store.enabled` / `.driver` / `.connection` / `.refresh-interval` | `false` / `database` / `null` / `5s` | the writable layer |
| `context.tenant-attribute` | `tenant` | the principal attribute holding the tenant id |
| `web.disabled-status` | `404` | the status of a gated route or method that is off: 404, 403 or 503 |
| `events.evaluations` | `false` | publish `FeatureFlagEvaluated` |
| `management.writes` | `false` | allow writes from the admin page, the actuator and the CLI |
| `server.enabled` / `.path` / `.token` / `.allow-anonymous` | `false` / `/feature-flags/flagd.json` / `''` / `false` | the sync server |

## Troubleshooting

- **Every gate is closed.** `firefly.feature-flags.enabled` is off, or the key is misspelt: gates fail closed. The
  actuator's `GET /actuator/flags/{key}` answers `unknown-flag` for a key no source defines.
- **A change does not show.** Each source is re-checked once per `refresh-interval`; the writing process sees its
  own write when its local refresh succeeds. If that refresh is deferred or fails, the committed write remains
  durable and normal polling restores local visibility. A store write made with the cache store down is seen by
  other workers when their own interval elapses.
- **The boot fails with `Invalid feature flag [...]`.** A definition in `flags` or in the flag file breaks a rule
  above; the message names the key, the source and the rule.
- **The boot rejects a source or sync server setting.** An enabled file source needs a path. An enabled HTTP source
  needs a URL and a positive `refresh-interval`; zero is allowed for the cheaper file and store checks. An enabled
  sync server needs a token unless `allow-anonymous` is true, and its path cannot be empty or `/`.
- **Sync returns 401/403 before the controller.** The sync controller's bearer token does not bypass application
  Security HTTP/JWT filters. Configure the sync path explicitly in the application's security rules so its intended
  machine client can reach the controller, and retain the controller's own token check. Inspect both filter and
  controller responses before changing credentials.
- **`writes-disabled` / `not-writable`.** Set `firefly.feature-flags.management.writes`, and enable the store.
- **A percentage rollout ignores anonymous users.** They have no targeting key; contribute a stable anonymous id
  as `targetingKey` from a context contributor, or target on another attribute.
- **`#[FeatureFlag]` refuses the boot.** The scanner names the method and the reason: remove `final`, move the
  attribute to a public instance method, or name a fallback that exists.
