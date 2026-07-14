# firefly/config

LaraFly's configuration layer over Laravel's `Config\Repository`: Spring-style profiles, a typed config accessor
(`string()`/`int()`/`bool()`/`array()` with fail-fast on missing/mismatched keys), `#[ConfigProperties]` DTO binding
compiled to a cached manifest, and a config-backed `ValueResolver` that resolves `#[Value('${…}')]` against
application config.

Apache-2.0 © Firefly Software Solutions Inc.
