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
`FieldError` per failed field message, each with the rejected value. `firefly/web` renders these as RFC-7807,
and it is `firefly/web` that performs the `#[Valid]` interception on a `#[RequestBody]` DTO — this package
owns the constraints and the primitive, not the HTTP plumbing.

## Constraint attributes

Declare constraints on a DTO's promoted constructor parameters (or properties) and let `ConstraintScanner`
compile them into the manifest `BeanValidator` reads: `#[NotNull]`, `#[NotEmpty]`, `#[NotBlank]`, `#[Min]`,
`#[Max]`, `#[Size]`, `#[Digits]`, `#[Pattern]`, `#[Email]`, `#[Past]`, `#[Future]`, `#[AssertTrue]`,
`#[AssertFalse]`, `#[Positive]`, `#[PositiveOrZero]`, `#[Negative]`, `#[NegativeOrZero]`, plus the
domain-shaped `#[Iban]`, `#[Bic]`, `#[Swift]`, `#[Isin]`, `#[Cusip]`, `#[RoutingNumber]`, `#[Luhn]`,
`#[CurrencyCode]`, `#[CountryCode]`, `#[LanguageTag]`, `#[UuidValue]`, `#[Phone]`, `#[PostalCode]`,
`#[Percentage]`, `#[Money]`, `#[DecimalScale]`.

### `null` is valid for every constraint except `#[NotNull]`

Jakarta's null contract, honoured literally: rejecting `null` is `@NotNull`'s single job (and that of the
constraints subsuming it, `#[NotEmpty]`/`#[NotBlank]`), and every other constraint short-circuits to "valid"
on null, so an optional field never has to be spelled "email-or-null". `ConstraintScanner` implements that by
prepending Laravel's `nullable` flag to a nullable property's compiled rule list at **compile** time.

The one exception is a rule **object** whose whole purpose is to have an opinion about null: rule objects are
never implicit to Illuminate, so `nullable` would silently disable them. Such a rule implements
`Firefly\Validation\Rule\NullAware` (Firefly's own `NotNull` does) and keeps firing. A present-but-null
value used to fail *every* constraint on the property rather than only `@NotNull`.

### `#[Size]` always means length

`#[Size]` is a length/size constraint, always, whatever else is declared on the same property. It used to emit
Laravel's `min:`/`max:`/`between:` strings, whose meaning `Validator::getSize()` decides at runtime from the
property's **other** rules — value semantics when a sibling contributes `numeric`, size semantics otherwise.
Pairing `#[Size]` with `#[Min]`/`#[Max]`/`#[Digits]`/`#[Positive]` (all of which emit `numeric`) therefore
turned a length check into a magnitude check with no warning. It now wraps a first-party
`Firefly\Validation\Rule\Size` that measures the value and never reads the sibling list. An unbounded
`#[Size]` (neither `min` nor `max`) contributes nothing.

### `#[Rules]` — the escape hatch, and `Compilable`

`#[Rules]` attaches any Laravel rule string or `ValidationRule` object directly, for a rule with no bespoke
constraint attribute. Because the compiled manifest is a `var_export`ed array literal, a rule object cannot be
written into it; it is stored as `['@rule' => Class, 'args' => [...]]` and rebuilt with
`new $class(...$args)` at load.

`ConstraintManifestCompiler` recovers `args` automatically for the ordinary PHP 8 shape — every constructor
parameter promoted to a property — since promotion guarantees a property mirrors each parameter. A rule that
is **not** promotion-shaped (it normalises its input, renames, or does not keep a value) must implement
`Firefly\Validation\Rule\Compilable` and declare its arguments itself; the values must be `var_export`-safe
(null, scalars, enums, or arrays of those). A rule that is neither is **rejected at compile time** with an
actionable `ConfigurationException` — it is never silently rehydrated with defaults at boot, which is what
used to happen (`new StartsWith('ACME')` compiled to `['@rule' => StartsWith::class]` and booted as
`new StartsWith()`).

## Domain rules

Each rule is an `Illuminate\Contracts\Validation\ValidationRule`, usable directly or inside `validate()`:
`Iban`, `Bic`, `Swift`, `Isin`, `Cusip`, `RoutingNumber`, `Luhn`, `Currency`, `CountryCode`, `LanguageTag`,
`Uuid`, `E164`, `PostalCode`, `Percentage`, `PositiveMoney`, `DecimalScale`. The `Currency`/`CountryCode`
allowlists ship a representative ISO subset — extend the constants in the rule for the full set.

## Auto-configuration & override

Installing `firefly/validation` binds a default `Validator` (`IlluminateValidator`) via
`ValidationAutoConfiguration`, gated `#[ConditionalOnMissingBean(Validator::class)]`. Bind your own `Validator`
in the app and the default backs off automatically.
