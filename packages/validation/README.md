# firefly/validation

LaraFly's validation module: a `Validator` port with a Spring-style `validate(data, rules)` primitive over
`Illuminate\Validation`, a `#[Valid]` marker (interception lands in M6/web), ~16 financial-domain `Rule` objects
(IBAN, BIC, Luhn, ISIN, ...), and a `ValidationAutoConfiguration` that installs the default adapter and backs
off when the application binds its own `Validator`. Validation failures throw the kernel's `ValidationException`
(HTTP 422) carrying `FieldError`s; the web layer renders them.

Apache-2.0 © Firefly Software Solutions Inc.
