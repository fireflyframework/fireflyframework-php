<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Validation\Validator;

/**
 * Bean Validation's entry point: validate an associative payload against the compiled constraint rules for
 * a DTO class. Delegates to the M5 Validator port (IlluminateValidator by default), so a failure throws the
 * kernel's ValidationException (HTTP 422) with FieldErrors already shaped — the web #[Valid] interceptor
 * (M6) calls this; it never re-implements validation.
 */
final class BeanValidator
{
    public function __construct(
        private readonly Validator $validator,
        private readonly ConstraintManifest $manifest,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed> the validated subset
     *
     * @throws ValidationException
     */
    public function validate(array $data, string $dtoClass): array
    {
        return $this->validator->validate($data, $this->manifest->rulesFor($dtoClass));
    }

    /**
     * @throws ValidationException
     */
    public function validateObject(object $dto): void
    {
        // get_object_vars() from outside the object's scope captures its PUBLIC properties — the shape
        // LaraFly DTOs use (public readonly promoted params). The primary path is validate($array, $class),
        // which the ArgumentResolver drives with the raw decoded body; this is a convenience for callers
        // holding an already-built DTO.
        $data = [];
        foreach (get_object_vars($dto) as $property => $value) {
            $data[(string) $property] = $value;
        }

        $this->validate($data, $dto::class);
    }
}
