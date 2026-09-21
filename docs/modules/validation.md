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
`FieldError` per failed message, each with the rejected value. The primitive takes raw Laravel rules, has no
constraints to describe, and keeps Laravel's keys and sentences whatever `firefly.validation.messages` says.
`firefly/web` renders these as RFC-7807, and it is `firefly/web` that performs the `#[Valid]` interception
on a `#[RequestBody]` DTO — this package owns the constraints and the primitive, not the HTTP plumbing.

## Constraint attributes

Declare constraints on a DTO's promoted constructor parameters (or properties) and let `ConstraintScanner`
compile them into the manifest `BeanValidator` reads: `#[NotNull]`, `#[NotEmpty]`, `#[NotBlank]`, `#[Min]`,
`#[Max]`, `#[Size]`, `#[Digits]`, `#[Pattern]`, `#[Email]`, `#[Past]`, `#[Future]`, `#[AssertTrue]`,
`#[AssertFalse]`, `#[Positive]`, `#[PositiveOrZero]`, `#[Negative]`, `#[NegativeOrZero]`, plus the
domain-shaped `#[Iban]`, `#[Bic]`, `#[Swift]`, `#[Isin]`, `#[Cusip]`, `#[RoutingNumber]`, `#[Luhn]`,
`#[CurrencyCode]`, `#[CountryCode]`, `#[LanguageTag]`, `#[UuidValue]`, `#[Phone]`, `#[PostalCode]`,
`#[Percentage]`, `#[Money]`, `#[DecimalScale]`. Every one of them takes Bean Validation's `message` element
(`#[NotBlank(message: 'give us a name')]`, with `{min}`/`{max}`/`{value}`/`{regexp}` placeholders filled
in), and every one publishes a sentence of its own when it fails — see [Field errors](#field-errors-shaped-like-springs).

### `#[Valid]` cascades into nested DTOs and into list elements

`#[Valid]` on a class-typed member compiles that class's constraints under dot-prefixed keys
(`shipTo.postcode`). On a member typed `array` (or `iterable`) it cascades into every **element**: the
element class is read, in this order, from `#[Valid(each: OrderLine::class)]`, from a `@var list<OrderLine>`
docblock on the member, or from the constructor's `@param list<OrderLine> $lines` tag — the same tag
`firefly/web`'s `RouteScanner` hydrates from, through the same resolver
(`Firefly\Validation\Constraint\ContainerElementType`), so a list the validator checks element by element is a
list the hydrator builds element by element. `list<X>`, `array<int, X>`, `iterable<X>` and `X[]` all mean
the same list. Each element's constraints are compiled under Laravel's wildcard key (`lines.*.sku`); a failure
is reported as `lines[1].sku` — Spring's spelling, the path the client wrote.

```php
final readonly class OrderRequest
{
    /** @param list<OrderLinePayload> $lines */
    public function __construct(
        #[NotEmpty] #[Size(min: 1, max: 50)] #[Valid]
        public array $lines,
    ) {}
}
```

A `#[Valid]` list whose element class cannot be told — no docblock, a list of scalars (`list<string>`), a
nested list (`list<list<X>>`) — is refused at scan time (`firefly:cache`, or the in-process scan of an
uncached boot) with a `ConfigurationException` naming the member and the three ways to state the class. A
`#[Valid]` that silently did nothing is how a bad element used to reach its constructor and come back as a
400 rather than a 422. A self-referential list (`Tree { #[Valid] list<Tree> $children }`) expands one level,
like a self-referential object.

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

## Field errors, shaped like Spring's

A 422's `errors` list is the shape of Spring's `FieldError`: the field path exactly as the client sent it, a
sentence that describes the **constraint**, and the constraint's name.

```json
{
  "status": 422,
  "code": "VALIDATION_ERROR",
  "errors": [
    { "field": "shipTo.postcode", "message": "must not be blank", "constraint": "NotBlank", "rejectedValue": "" },
    { "field": "lines[1].sku", "message": "must match \"^[A-Z0-9][A-Z0-9-]{2,31}$\"", "constraint": "Pattern", "rejectedValue": "bad sku!" },
    { "field": "lines[1].quantity", "message": "must be greater than 0", "constraint": "Positive", "rejectedValue": 0 }
  ]
}
```

Laravel's validator words a failure about the **attribute** — `The ship to.street field is required.`, the
dotted path humanised into prose nobody wrote — so `ConstraintScanner` compiles, beside each property's rules,
which constraint contributed each rule and what it says (`ConstraintDescriptor`, under the manifest's
`@constraints` key), and `FieldErrorMapper` hands each rule Laravel reports as failed (`Validator::failed()`,
by name **and parameters**, which is how `#[Pattern]`'s `regex` is told from `#[NotBlank]`'s on the same
property) back to the constraint that owns it. One violation is reported per constraint.

| Constraint | Sentence |
|---|---|
| `NotBlank` / `NotEmpty` / `NotNull` | `must not be blank` / `must not be empty` / `must not be null` |
| `Size(min, max)` | `size must be between {min} and {max}` — `size must be at least {min}` / `size must be at most {max}` with one bound |
| `Min` / `Max` | `must be greater than or equal to {value}` / `must be less than or equal to {value}` |
| `Positive` / `PositiveOrZero` | `must be greater than 0` / `must be greater than or equal to 0` |
| `Negative` / `NegativeOrZero` | `must be less than 0` / `must be less than or equal to 0` |
| `Digits(integer, fraction)` | `numeric value out of bounds (<{integer} digits>.<{fraction} digits> expected)` |
| `Pattern(regex)` | `must match "{regexp}"` — the expression without its PCRE delimiters and modifiers |
| `Email` | `must be a well-formed email address` |
| `Past` / `Future` | `must be a past date` / `must be a future date` |
| `AssertTrue` / `AssertFalse` | `must be true` / `must be false` |
| `Iban` / `Bic` / `Swift` / `Isin` / `Cusip` | `must be a valid IBAN` / `must be a valid BIC` / `must be a valid SWIFT code` / `must be a valid ISIN` / `must be a valid CUSIP` |
| `RoutingNumber` / `Luhn` | `must be a valid ABA routing number` / `must pass the Luhn checksum` |
| `CurrencyCode` / `CountryCode` / `LanguageTag` | `must be a valid ISO 4217 currency code` / `must be a valid ISO 3166-1 alpha-2 country code` / `must be a valid BCP 47 language tag` |
| `UuidValue` / `Phone` / `PostalCode` | `must be a valid UUID` / `must be a valid E.164 phone number` / `must be a valid postal code` |
| `Percentage` / `Money` / `DecimalScale(scale)` | `must be a percentage between 0 and 100` / `must be a positive monetary amount with at most two decimals` / `must have at most {scale} fractional digits` |
| `Rules` | none — Laravel's sentence for the rule that failed, unless `message:` is given |

### The `message` element

`#[Size(min: 1, max: 50, message: 'between {min} and {max} lines')]` publishes `between 1 and 50 lines`:
the developer's sentence, with Bean Validation's `{placeholder}`s filled from the attribute's own elements,
and it wins in **both** message styles. `#[Rules]` takes it as a named argument
(`#[Rules('min:3', message: 'must be at least 3 characters')]`). A constraint attribute of your own states
its sentence by implementing `Firefly\Validation\Constraint\HasMessage`; one that does not keeps Laravel's
sentence for its rules and is still named in `constraint`.

### `firefly.validation.messages`

`constraint` (the default) is the shape above. `laravel` keeps the sentences Laravel's validator writes —
`The ship to.street field is required.` — for an application whose clients or tests already assert on them;
the field path and `constraint` are the same in both styles, only `message` differs. An application that
bound its own `Validator` (see below) is on the plain path: it receives the rules alone and answers as it
always has; the shipped adapter is a `Firefly\Validation\SmartValidator`, the interface that also receives
the descriptors.

## Domain rules

Each rule is an `Illuminate\Contracts\Validation\ValidationRule`, usable directly or inside `validate()`:
`Iban`, `Bic`, `Swift`, `Isin`, `Cusip`, `RoutingNumber`, `Luhn`, `Currency`, `CountryCode`, `LanguageTag`,
`Uuid`, `E164`, `PostalCode`, `Percentage`, `PositiveMoney`, `DecimalScale`. The `Currency`/`CountryCode`
allowlists ship a representative ISO subset — extend the constants in the rule for the full set.

## Auto-configuration & override

Installing `firefly/validation` binds a default `Validator` (`IlluminateValidator`) via
`ValidationAutoConfiguration`, gated `#[ConditionalOnMissingBean(Validator::class)]`. Bind your own `Validator`
in the app and the default backs off automatically.

## Configuration

| Key | Default | Purpose |
|---|---|---|
| `firefly.validation.messages` | `constraint` | How a 422's field errors are worded: `constraint` — the constraint's own sentence, Spring's shape; `laravel` — Laravel's humanised sentences. Anything else is refused at boot. A `message:` element on the attribute wins in both. |
