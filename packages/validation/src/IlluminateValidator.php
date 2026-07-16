<?php

declare(strict_types=1);

namespace Firefly\Validation;

use Firefly\Kernel\Error\FieldError;
use Firefly\Kernel\Exception\Business\ValidationException;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\Arr;

/**
 * The default Validator adapter over Illuminate\Validation. Maps a failing validation run's MessageBag into a
 * list<FieldError> (one per (field, message) pair, carrying the rejected input value) and throws the kernel's
 * ValidationException (status 422, errorCode VALIDATION_ERROR) — the exact shape the web layer (M6) renders as
 * RFC-7807. On success it returns Illuminate's validated() subset.
 */
final class IlluminateValidator implements Validator
{
    public function __construct(private readonly Factory $factory) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>
     */
    public function validate(array $data, array $rules): array
    {
        $validator = $this->factory->make($data, $rules);

        if ($validator->fails()) {
            $fieldErrors = [];

            /** @var array<string, list<string>> $messages */
            $messages = $validator->errors()->messages();
            foreach ($messages as $field => $fieldMessages) {
                foreach ($fieldMessages as $message) {
                    $fieldErrors[] = new FieldError(
                        field: $field,
                        message: $message,
                        code: null,
                        rejectedValue: Arr::get($data, $field),
                    );
                }
            }

            throw new ValidationException('Validation failed', $fieldErrors);
        }

        /** @var array<string,mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }
}
