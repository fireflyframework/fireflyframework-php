<?php

declare(strict_types=1);

use Firefly\Kernel\Error\FieldError;
use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Validation\Constraint\BeanValidator;
use Firefly\Validation\Constraint\ConstraintDescriptor;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\Constraint\ConstraintManifestCompiler;
use Firefly\Validation\Constraint\FieldErrorMapper;
use Firefly\Validation\IlluminateValidator;
use Firefly\Validation\MessageStyle;
use Firefly\Validation\SmartValidator;
use Firefly\Validation\Tests\Fixtures\Constraint\SkuPayload;
use Firefly\Validation\Tests\Fixtures\ValidatorHarness;
use Firefly\Validation\Validator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

/**
 * @param  array<string, mixed>  $overrides
 * @return list<array<string, mixed>> every FieldError as the wire sees it (empty === the payload validated)
 */
function skuErrors(array $overrides, MessageStyle $style = MessageStyle::Constraint): array
{
    $data = ['sku' => 'WIDGET-1', 'quantity' => 2, 'name' => 'Ada', 'tag' => 'abc', 'label' => 'abc', ...$overrides];

    try {
        ValidatorHarness::beanValidatorWith($style, SkuPayload::class)->validate($data, SkuPayload::class);

        return [];
    } catch (ValidationException $e) {
        return array_map(static fn (FieldError $error): array => $error->toArray(), $e->fieldErrors());
    }
}

it('words a failure by the constraint that failed and names it', function () {
    expect(skuErrors(['sku' => 'bad sku!']))->toBe([
        ['field' => 'sku', 'message' => 'must match "^[A-Z0-9][A-Z0-9-]{2,31}$"', 'constraint' => 'Pattern', 'rejectedValue' => 'bad sku!'],
    ]);
});

it('attributes a rule name two constraints share by the parameters of the one that failed', function () {
    // '' fails #[NotBlank]'s `required` and, being blank, runs nothing else — never Pattern's regex.
    expect(skuErrors(['sku' => '']))->toBe([
        ['field' => 'sku', 'message' => 'must not be blank', 'constraint' => 'NotBlank', 'rejectedValue' => ''],
    ]);

    // An array where a string was expected fails NotBlank's `string` AND both regexes; Laravel records the
    // LAST regex's parameters, which are Pattern's — so the two violations are NotBlank and Pattern, once each.
    expect(array_column(skuErrors(['sku' => ['x']]), 'constraint'))->toBe(['NotBlank', 'Pattern']);
});

it('reports one violation per constraint however many of its rules failed', function () {
    // 'abc' fails `numeric`, `gt:0` and `lte:999`; `numeric` and `gt:0` are both #[Positive]'s.
    expect(skuErrors(['quantity' => 'abc']))->toBe([
        ['field' => 'quantity', 'message' => 'must be greater than 0', 'constraint' => 'Positive', 'rejectedValue' => 'abc'],
        ['field' => 'quantity', 'message' => 'must be less than or equal to 999', 'constraint' => 'Max', 'rejectedValue' => 'abc'],
    ])
        ->and(skuErrors(['quantity' => 0]))->toBe([
            ['field' => 'quantity', 'message' => 'must be greater than 0', 'constraint' => 'Positive', 'rejectedValue' => 0],
        ])
        ->and(skuErrors(['quantity' => 1000]))->toBe([
            ['field' => 'quantity', 'message' => 'must be less than or equal to 999', 'constraint' => 'Max', 'rejectedValue' => 1000],
        ]);
});

it('lets the message element win, and keeps Laravel\'s sentence for a #[Rules] that gave none', function () {
    expect(skuErrors(['name' => '']))->toBe([
        ['field' => 'name', 'message' => 'give us a name', 'constraint' => 'NotBlank', 'rejectedValue' => ''],
    ])
        ->and(skuErrors(['label' => 'ab']))->toBe([
            ['field' => 'label', 'message' => 'must be at least 3 characters', 'constraint' => 'Rules', 'rejectedValue' => 'ab'],
        ])
        // The test translator has no lang lines, so Laravel's sentence is its key — and it is Laravel's,
        // not a sentence this framework invented for a rule it knows nothing about.
        ->and(skuErrors(['tag' => 'ab']))->toBe([
            ['field' => 'tag', 'message' => 'validation.min.string', 'constraint' => 'Rules', 'rejectedValue' => 'ab'],
        ]);
});

it('keeps Laravel\'s sentences in the laravel style, one per failed rule, still naming the constraint', function () {
    expect(skuErrors(['sku' => 'bad sku!'], MessageStyle::Laravel))->toBe([
        ['field' => 'sku', 'message' => 'validation.regex', 'constraint' => 'Pattern', 'rejectedValue' => 'bad sku!'],
    ])
        ->and(skuErrors(['quantity' => 'abc'], MessageStyle::Laravel))->toBe([
            ['field' => 'quantity', 'message' => 'validation.numeric', 'constraint' => 'Positive', 'rejectedValue' => 'abc'],
            ['field' => 'quantity', 'message' => 'validation.gt.numeric', 'constraint' => 'Positive', 'rejectedValue' => 'abc'],
            ['field' => 'quantity', 'message' => 'validation.lte.numeric', 'constraint' => 'Max', 'rejectedValue' => 'abc'],
        ])
        // The message element wins in this style too: it is the developer's sentence, not Laravel's.
        ->and(skuErrors(['name' => ''], MessageStyle::Laravel))->toBe([
            ['field' => 'name', 'message' => 'give us a name', 'constraint' => 'NotBlank', 'rejectedValue' => ''],
        ]);
});

it('spells a list element the way the client wrote it and finds its constraints under the wildcard key', function () {
    $manifest = new ConstraintManifest(
        ['App\\Cart' => ['lines.*.sku' => ['required']]],
        ['App\\Cart' => ['lines.*.sku' => [new ConstraintDescriptor('NotBlank', 'must not be blank', [['Required', []]])]]],
    );
    $validator = new BeanValidator(new IlluminateValidator(new IlluminateFactory(new Translator(new ArrayLoader, 'en'))), $manifest);

    try {
        $validator->validate(['lines' => [['sku' => 'A'], []]], 'App\\Cart');
        throw new RuntimeException('expected the second line to fail');
    } catch (ValidationException $e) {
        expect(array_map(static fn (FieldError $error): array => $error->toArray(), $e->fieldErrors()))->toBe([
            ['field' => 'lines[1].sku', 'message' => 'must not be blank', 'constraint' => 'NotBlank'],
        ]);
    }

    expect(FieldErrorMapper::manifestPath('lines.0.options.12.code'))->toBe('lines.*.options.*.code')
        ->and(FieldErrorMapper::fieldPath('lines.0.options.12.code'))->toBe('lines[0].options[12].code')
        ->and(FieldErrorMapper::fieldPath('shipTo.street'))->toBe('shipTo.street');
});

it('is a SmartValidator, and leaves a custom Validator that is not one on the plain path', function () {
    expect(new IlluminateValidator(new IlluminateFactory(new Translator(new ArrayLoader, 'en'))))->toBeInstanceOf(SmartValidator::class);

    $plain = new class implements Validator
    {
        /** @var list<string> */
        public array $seen = [];

        /**
         * @param  array<string,mixed>  $data
         * @param  array<string,mixed>  $rules
         * @return array<string,mixed>
         */
        public function validate(array $data, array $rules): array
        {
            $this->seen = array_keys($rules);

            return $data;
        }
    };
    $manifest = ConstraintManifest::fromArray((new ConstraintManifestCompiler)->toArray([SkuPayload::class]));

    (new BeanValidator($plain, $manifest))->validate(['sku' => 'X'], SkuPayload::class);

    expect($plain->seen)->toBe(['sku', 'quantity', 'name', 'tag', 'label']);
});
