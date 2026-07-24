<?php

declare(strict_types=1);

use Firefly\Cqrs\Validation\MessageValidator;
use Firefly\Cqrs\Validation\Validatable;
use Firefly\Kernel\Exception\Business\ValidationException;
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

it('propagates the ValidationException the Validator throws for an invalid message (does not swallow)', function () {
    // A stub Validator that rejects like the shipped IlluminateValidator does on invalid data. MessageValidator
    // is a thin passthrough with no try/catch, so the 422 ValidationException must surface unchanged to the caller
    // (the command bus then wraps it category-preserving). A swallow/catch here would drop the failure.
    $rejecting = new class implements Validator
    {
        public function validate(array $data, array $rules): array
        {
            throw new ValidationException;
        }
    };

    expect(fn () => (new MessageValidator($rejecting))->validate(new CreateThing('')))
        ->toThrow(ValidationException::class);
});

it('is a no-op for a non-Validatable message', function () {
    $validator = recordingValidator();
    (new MessageValidator($validator))->validate(new stdClass);

    expect($validator->calls)->toBe([]);
});

it('is a no-op when no Validator is bound (validation package absent)', function () {
    (new MessageValidator(null))->validate(new CreateThing('x')); // must not throw
})->throwsNoExceptions();
