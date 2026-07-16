# Validation

`firefly/validation` provides a Spring-style validation primitive over Laravel's validator, plus a set of
financial-domain rules — and it is the reference example of an auto-configured capability.

## The `validate()` primitive

```php
$validated = $validator->validate(
    ['iban' => $request->input('iban')],
    ['iban' => ['required', new Iban]],
);
```

On failure it throws the kernel's `ValidationException` (HTTP 422, `errorCode` `VALIDATION_ERROR`) carrying one
`FieldError` per failed field message, each with the rejected value. The web layer (M6) renders these as
RFC-7807. Method-parameter `#[Valid]` interception on a `#[RequestBody]` DTO is also an M6/web concern; in M5,
`#[Valid]` is an inert marker.

## Domain rules

Each rule is an `Illuminate\Contracts\Validation\ValidationRule`, usable directly or inside `validate()`:
`Iban`, `Bic`, `Swift`, `Isin`, `Cusip`, `RoutingNumber`, `Luhn`, `Currency`, `CountryCode`, `LanguageTag`,
`Uuid`, `E164`, `PostalCode`, `Percentage`, `PositiveMoney`, `DecimalScale`. The `Currency`/`CountryCode`
allowlists ship a representative ISO subset — extend the constants in the rule for the full set.

## Auto-configuration & override

Installing `firefly/validation` binds a default `Validator` (`IlluminateValidator`) via
`ValidationAutoConfiguration`, gated `#[ConditionalOnMissingBean(Validator::class)]`. Bind your own `Validator`
in the app and the default backs off automatically.
