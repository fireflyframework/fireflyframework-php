<?php

declare(strict_types=1);

use Firefly\Cqrs\Validation\MessageValidator;
use Firefly\Cqrs\Validation\Validatable;
use Firefly\Validation\Validator;

/**
 * A recording stub of the shipped Validator port.
 *
 * @return Validator&object{calls: list<array{data: array<string,mixed>, rules: array<string,mixed>}>}
 */
function recordingValidator(): Validator
{
    return new class implements Validator
    {
        /** @var list<array{data: array<string,mixed>, rules: array<string,mixed>}> */
        public array $calls = [];

        public function validate(array $data, array $rules): array
        {
            $this->calls[] = ['data' => $data, 'rules' => $rules];

            return $data;
        }
    };
}

final class CreateThing implements Validatable
{
    public function __construct(public string $name = '') {}

    public function validationData(): array
    {
        return ['name' => $this->name];
    }

    public function validationRules(): array
    {
        return ['name' => 'required|string'];
    }
}

it('runs the shipped Validator over a Validatable message', function () {
    $validator = recordingValidator();
    (new MessageValidator($validator))->validate(new CreateThing('widget'));

    expect($validator->calls)->toHaveCount(1)
        ->and($validator->calls[0]['data'])->toBe(['name' => 'widget'])
        ->and($validator->calls[0]['rules'])->toBe(['name' => 'required|string']);
});

it('is a no-op for a non-Validatable message', function () {
    $validator = recordingValidator();
    (new MessageValidator($validator))->validate(new stdClass);

    expect($validator->calls)->toBe([]);
});

it('is a no-op when no Validator is bound (validation package absent)', function () {
    (new MessageValidator(null))->validate(new CreateThing('x')); // must not throw
})->throwsNoExceptions();
