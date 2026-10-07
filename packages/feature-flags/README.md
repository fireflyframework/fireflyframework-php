# firefly/feature-flags

Feature flags for LaraFly on OpenFeature. Flags are flagd flag definitions — the format the OpenFeature
ecosystem shares — evaluated in-process with the same semantics PyFly uses, so a user gets the same
variant and the same percentage bucket in a PHP service and in a Python one. Definitions come from
`firefly.feature-flags.flags`, a watched flagd file, another service's sync endpoint and a writable store;
the highest layer that defines a key wins. Code reads flags through the `FeatureFlags` service or any
OpenFeature client, gates a bean method or a route with `#[FeatureFlag]`, and a Blade view with
`@featureflag`. Operators change flags at runtime from the admin dashboard, the `flags` actuator endpoint
and `php artisan firefly:flags`, with every change recorded.

See [Feature Flags](../../docs/modules/feature-flags.md) for the full guide.

Apache-2.0 © Firefly Software Solutions Inc.
