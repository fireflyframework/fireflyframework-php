<?php

declare(strict_types=1);

use Firefly\Kernel\Error\FieldError;
use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Validation\Constraint\ConstraintManifestCompiler;
use Firefly\Validation\Constraint\ConstraintScanner;
use Firefly\Validation\Rule\Size;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\AliasedPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\CartPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\EachPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\MissingEachPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\NestedListPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\ScalarListPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\TreePayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\UntypedListPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\Lists\VarTaggedPayload;
use Firefly\Validation\Tests\Fixtures\ValidatorHarness;

/**
 * @param  array<string, mixed>  $data
 * @return list<array<string, mixed>> every FieldError as the wire sees it (empty === the payload validated)
 */
function cartErrors(array $data): array
{
    try {
        ValidatorHarness::beanValidator(CartPayload::class)->validate($data, CartPayload::class);

        return [];
    } catch (ValidationException $e) {
        return array_map(static fn (FieldError $error): array => $error->toArray(), $e->fieldErrors());
    }
}

/**
 * The compile a `firefly:cache` run makes of one class — the moment a #[Valid] list the scanner cannot see
 * into has to surface as a refusal, not as a manifest that silently validates nothing. The class_exists()
 * guard is what narrows a dataset's plain string to the class-string the compiler takes, and it doubles as
 * an assertion: a refusal case that named a fixture PHP cannot load would otherwise pass for the wrong reason.
 *
 * @return array<string, mixed>
 */
function compileAlone(string $class): array
{
    if (! class_exists($class)) {
        throw new RuntimeException("[{$class}] is not a loadable fixture.");
    }

    return (new ConstraintManifestCompiler)->toArray([$class]);
}

it('cascades #[Valid] into every element of a list, under Laravel\'s wildcard keys', function () {
    $scanner = new ConstraintScanner;
    $rules = $scanner->scan(CartPayload::class);
    $constraints = $scanner->constraints(CartPayload::class);

    expect(array_keys($rules))->toBe(['customer', 'lines', 'lines.*.sku', 'lines.*.quantity'])
        ->and($rules['lines'][0])->toBe('required')
        ->and($rules['lines'][1])->toBeInstanceOf(Size::class)
        ->and($rules['lines.*.sku'])->toBe(['required', 'string', 'regex:/\S/', 'regex:/^[A-Z0-9][A-Z0-9-]{2,31}$/D'])
        ->and(array_keys($constraints))->toBe(array_keys($rules))
        ->and($constraints['lines.*.sku'][1]->name)->toBe('Pattern')
        ->and($constraints['lines.*.quantity'][0]->name)->toBe('NotNull');
});

it('takes the element class from each: and from a @var tag when the constructor has no @param', function () {
    expect((new ConstraintScanner)->scan(EachPayload::class))->toHaveKeys(['lines.*.sku', 'lines.*.quantity'])
        ->and((new ConstraintScanner)->scan(VarTaggedPayload::class))->toHaveKeys(['lines.*.sku', 'lines.*.quantity'])
        ->and((new ConstraintScanner)->scan(AliasedPayload::class))->toHaveKeys(['lines.*.sku', 'lines.*.quantity']);
});

it('reports each failing element under its own path with the element constraint\'s sentence, and nothing else', function () {
    $errors = cartErrors([
        'customer' => 'Ada',
        'lines' => [
            ['sku' => 'WIDGET-1', 'quantity' => 2],
            ['sku' => 'bad sku!', 'quantity' => 0],
            [],
        ],
    ]);

    expect($errors)->toEqualCanonicalizing([
        ['field' => 'lines[1].sku', 'message' => 'must match "^[A-Z0-9][A-Z0-9-]{2,31}$"', 'constraint' => 'Pattern', 'rejectedValue' => 'bad sku!'],
        ['field' => 'lines[1].quantity', 'message' => 'must be greater than 0', 'constraint' => 'Positive', 'rejectedValue' => 0],
        ['field' => 'lines[2].sku', 'message' => 'must not be blank', 'constraint' => 'NotBlank'],
        ['field' => 'lines[2].quantity', 'message' => 'must not be null', 'constraint' => 'NotNull'],
    ])
        ->and(array_column($errors, 'field'))->not->toContain('lines.1.sku')
        ->and(cartErrors(['customer' => 'Ada', 'lines' => [['sku' => 'WIDGET-1', 'quantity' => 2]]]))->toBe([]);
});

it('still validates the list itself, and an absent or empty list is the list\'s own error, not an element\'s', function () {
    expect(cartErrors(['customer' => 'Ada']))->toBe([
        ['field' => 'lines', 'message' => 'must not be empty', 'constraint' => 'NotEmpty'],
    ])
        // Illuminate stops validating an attribute once a presence rule has failed on it (shouldStopValidating),
        // so #[Size] does not add a second violation for the empty list.
        ->and(cartErrors(['customer' => 'Ada', 'lines' => []]))->toBe([
            ['field' => 'lines', 'message' => 'must not be empty', 'constraint' => 'NotEmpty', 'rejectedValue' => []],
        ]);
});

it('stops a self-referential list after one level, as the object cascade does', function () {
    $rules = (new ConstraintScanner)->scan(TreePayload::class);

    expect($rules)->toHaveKeys(['label', 'children.*.label'])
        ->and($rules)->not->toHaveKey('children.*.children.*.label');
});

it('refuses, at compile time, a #[Valid] list whose element class it cannot see', function (string $class, string $member) {
    expect(fn () => compileAlone($class))
        ->toThrow(ConfigurationException::class, $class.'::$'.$member)
        ->toThrow(ConfigurationException::class, '#[Valid(each:')
        ->toThrow(ConfigurationException::class, '@var list<')
        ->toThrow(ConfigurationException::class, 'list<list<');
})->with([
    'no docblock' => [UntypedListPayload::class, 'items'],
    'a list of scalars' => [ScalarListPayload::class, 'tags'],
    'a nested list' => [NestedListPayload::class, 'grid'],
]);

it('refuses an each: that names no class, by name', function () {
    expect(fn () => compileAlone(MissingEachPayload::class))
        ->toThrow(ConfigurationException::class, 'App\\Missing\\LinePayload');
});
