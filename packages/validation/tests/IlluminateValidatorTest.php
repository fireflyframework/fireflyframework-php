<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Validation\IlluminateValidator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;

function illuminateValidator(): IlluminateValidator
{
    return new IlluminateValidator(new Factory(new Translator(new ArrayLoader, 'en')));
}

it('returns the validated subset when the data passes', function () {
    $validated = illuminateValidator()->validate(
        ['name' => 'Ada', 'extra' => 'ignored'],
        ['name' => 'required|string'],
    );

    expect($validated)->toBe(['name' => 'Ada']);
});

it('throws a kernel ValidationException (422) carrying one FieldError per failed message, with the rejected value', function () {
    try {
        illuminateValidator()->validate(['age' => 'abc'], ['age' => 'required|integer']);
        throw new RuntimeException('expected a ValidationException');
    } catch (ValidationException $e) {
        expect($e->httpStatus())->toBe(422);

        $errors = $e->fieldErrors();
        expect($errors)->toHaveCount(1)
            ->and($errors[0]->field)->toBe('age')
            ->and($errors[0]->rejectedValue)->toBe('abc')
            ->and($errors[0]->message)->toBeString()->not->toBe('');
    }
});
