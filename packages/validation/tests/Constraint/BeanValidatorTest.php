<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Validation\Constraint\BeanValidator;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\Constraint\ConstraintManifestCompiler;
use Firefly\Validation\IlluminateValidator;
use Firefly\Validation\Tests\Fixtures\Constraint\MoneyTransferRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

/**
 * @param  class-string  ...$classes
 */
function beanValidatorFor(string ...$classes): BeanValidator
{
    $manifest = ConstraintManifest::fromArray((new ConstraintManifestCompiler)->toArray(array_values($classes)));
    $validator = new IlluminateValidator(new IlluminateFactory(new Translator(new ArrayLoader, 'en')));

    return new BeanValidator($validator, $manifest);
}

it('accepts a valid payload and returns the validated subset', function () {
    $validator = beanValidatorFor(MoneyTransferRequest::class);

    $validated = $validator->validate([
        'account' => 'GB82WEST12345698765432',
        'amount' => '19.99',
        'reference' => 'Invoice 42',
        'beneficiary' => ['street' => '1 Main St', 'postcode' => '28013'],
    ], MoneyTransferRequest::class);

    $beneficiary = $validated['beneficiary'];
    if (! is_array($beneficiary)) {
        throw new RuntimeException('Expected the validated beneficiary to be an array.');
    }

    expect($validated['account'])->toBe('GB82WEST12345698765432')
        ->and($beneficiary['postcode'])->toBe('28013');
});

it('throws a structured ValidationException with FieldErrors on an invalid payload', function () {
    $validator = beanValidatorFor(MoneyTransferRequest::class);

    try {
        $validator->validate([
            'account' => 'not-an-iban',
            'amount' => '0',
            'reference' => '',
            'beneficiary' => ['street' => '', 'postcode' => '@@@'],
        ], MoneyTransferRequest::class);

        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        $fields = array_map(fn ($fe) => $fe->field, $e->fieldErrors());

        expect($e->httpStatus())->toBe(422)
            ->and($fields)->toContain('account')
            ->and($fields)->toContain('amount')
            ->and($fields)->toContain('beneficiary.postcode');
    }
});
