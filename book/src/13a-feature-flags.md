<span class="eyebrow">Part IV — Observability, Testing & Delivery · Chapter 13A</span>

# Feature Flags Across LaraFly and PyFly {.chtitle}

By the end of this chapter you will use `FeatureFlags` in Lumen, distinguish a disabled flag from a caller default, target a stable cohort, operate a store without losing an outer transaction, and test a rollout with `withFeatureFlags()`. Chapters 3, 4, 9, 11, 12 and 13 supply the configuration, HTTP, transaction, actuator, testing and CLI foundations used here.

---

## Start dark, then choose a variant

Install the published LaraFly library as usual and enable `firefly.feature-flags.enabled` in `config/firefly.php`. The subsystem is off by default. A configured flag may use boolean or string shorthand; a file, HTTP document or store write must use the full flagd definition. `true` means enabled with `on`/`off` boolean variants and `on` as the default; `false` chooses `off`. A string such as `v2` creates one string variant. A number or object has no shorthand. See the configuration reference in the module guide for every key.

Lumen's checked-in rollout keeps the existing wallet wording when its boolean flag is dark and chooses a named view from a multivariate flag:

<!-- source: samples/lumen/src/Application/WalletRollout.php -->
```php
<?php

declare(strict_types=1);

namespace Lumen\Application;

use Firefly\FeatureFlags\FeatureFlags;

final class WalletRollout
{
    public function __construct(private readonly FeatureFlags $flags) {}

    public function balanceLabel(): string
    {
        return $this->flags->isEnabled('wallet-balance-v2') ? 'Available balance' : 'Balance';
    }

    public function checkoutView(): string
    {
        return match ($this->flags->getString('wallet-checkout-view', 'legacy')) {
            'v2' => 'wallet.v2',
            default => 'wallet.legacy',
        };
    }
}
```

The sample boots `FeatureFlagsServiceProvider` and `FeatureFlagsWiringProvider` in its test harness, with `wallet-balance-v2` off and `wallet-checkout-view` set to `legacy`. Its focused test flips both through `withFeatureFlags()`, checks the visible result, clears the override and checks restoration. The `getString()` caller default also maps a missing or invalid flag to the legacy view. `details()` returns the value, variant, reason, error code and metadata when a caller needs to explain an assignment; `getInt()`, `getFloat()` and `getObject()` request other types. A boolean flag is never treated as a number. A flag in state `DISABLED` returns the caller default, with reason `DISABLED`; a missing flag returns the caller default with `FLAG_NOT_FOUND`.

## Targeting and stable cohorts

A full definition has `state`, `variants`, an optional `defaultVariant`, JSON Logic `targeting` and scalar `metadata`. A targeting rule returns a variant name; `null` selects the default. Shared `$evaluators` can be referenced structurally with `{"$ref":"name"}`; a missing or cyclic reference fails that evaluation with `PARSE_ERROR`. LaraFly and PyFly run the same versioned conformance vectors. Lumen's test consumes the shared `pro-reports` cases: a `pro` plan resolves `on`, a free plan resolves `off`, and both assert the variant and `TARGETING_MATCH` reason.

For percentage rollouts, `fractional` hashes the flag key and stable `targetingKey` with unsigned MurmurHash3 x86 32-bit. The same key lands in the same bucket in both frameworks. Anonymous traffic has no stable key and receives the default variant unless the application contributes one. Use an authenticated user id or another durable identifier; a changing session id silently moves users between cohorts.

The ambient context includes `application`, `profiles`, principal name, `roles` without `ROLE_`, and `tenant` from the configured principal attribute. A `#[Component]` implementing `EvaluationContextContributor` can add `plan`; later contributors override earlier ones, and the caller's explicit attributes override them all. An explicit `targetingKey` accepts a nonempty string or integer in JSON context; a separate nonempty key argument takes priority. LaraFly drops unsupported attributes, including Eloquent/Arrayable/Jsonable objects and numeric-looking names, with a DEBUG diagnostic. Pass a model's id, not the model. Date and time values become UTC epoch milliseconds for both evaluators.

## Gate a method, route or view

`#[FeatureFlag('wallet-balance-v2')]` gates a proxied bean method; `fallback:` invokes a sibling method with the original arguments. On a controller action, the route middleware refuses before binding; a fallback that needs bound arguments runs in the method interceptor. Route files can use `feature-flag:key,variant`, and Blade offers `@featureflag` and `@featurevariant`. A refused route returns the configured 404, 403 or 503 problem response without disclosing the key. The default gate is closed: missing, disabled or erroring flags do not authorize the feature. Set `default: true` only for a deliberate open fallback. A flag cannot change bean registration after boot; Chapter 3's property condition handles that distinct decision.

The checked-in `WalletRolloutGate` makes the fallback concrete; its test calls the proxied bean before, during and after an override:

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

## Sources and operator changes

The precedence is config → watched JSON/YAML file → HTTP sync client → writable store → test overrides. A winning source supplies the whole definition. Source failures keep the last accepted document; an invalid initial config or file refuses startup. Replace files atomically so a reader never sees a partially written document. Quote YAML date-like keys and values for JSON/PHP portability. Remote clients use bearer authentication and conditional ETags; the server omits test overrides. A first unsolicited 304 is an error, while a valid body without ETag gets a SHA-256 revision.

The relational store uses `firefly_feature_flags` and `firefly_feature_flag_changes`: one versioned definition and one audit row in the same transaction. Supply `expectedVersion` to detect an operator conflict. Enabling or changing the default of a lower-layer flag copies its effective definition into the store; deleting that override reveals the lower layer again. A write in an outer transaction is visible only after its caller commits, and a rollback removes both the flag and audit change. A native MariaDB snapshot abort invalidates the parent transaction: roll back and retry the complete caller operation. A successful durable write can return a `refreshPending` receipt when its local refresh is deferred or refused; polling recovers visibility from the last good composition.

The `flags` actuator and admin dashboard provide list, details, preview, history and guarded writes. The actuator must be exposed explicitly and writes also need `firefly.feature-flags.management.writes` plus a store. `firefly:flags` is the matching CLI; verify the installed release's exact subcommands with its help output. For `evaluate`, omit `--context` or pass `--context='{}'` for no explicit attributes; a supplied value must be JSON-object text, or the command exits 1 with `bad-request` before evaluating. A preview sees only the supplied context plus `application` and `profiles`; it never inherits the operator's principal and emits neither evaluation metric nor exposure event. An external OpenFeature provider can supply evaluations, but Firefly's registry, writable store, source history and management semantics require Firefly's provider. Do not assume an external provider offers those operations.

## Observe, clean up and test

Each ordinary evaluation increments `feature_flag_evaluations_total` when a meter is available and `firefly.observability.metrics.enabled` is on (the default), labeled by flag, variant and reason. With metrics disabled or no meter available, `NoOpFeatureFlagMetrics` preserves evaluation without recording a counter. Set `events.evaluations` to publish `FeatureFlagEvaluated` exposure records. A value graph with more than 10,000 occurrences omits that event while preserving the evaluation and metric. The `featureflags` health component reports source state and expired flag debt. An expired flag still evaluates; remove it after the experiment or rollout ends.

Chapter 12's `withFeatureFlags()` sets an in-process layer above every source and returns an object with `set()`, `forget()`, `merge()` and `clear()`. Always clear an override when a test shares a running app. Lumen's focused tests exercise dark/on/default behavior and the exact shared targeting fixture, so this chapter's listing is executable evidence. For the full validation and wire rules, read the site’s Feature Flag Contract reference.

## Summary

Choose caller defaults deliberately, use stable targeting keys, keep store changes versioned, and test both rollout and restoration. Management preview and ordinary evaluation have different telemetry behavior; use the right path for the question you are answering.
