<?php

declare(strict_types=1);

namespace Firefly\Validation;

use Firefly\Kernel\Exception\Business\ValidationException;

/**
 * The validation PORT: validate an associative $data array against $rules, returning the validated subset or
 * throwing the kernel's ValidationException (HTTP 422) with per-field FieldErrors. Rules may be Laravel rule
 * strings/arrays or Firefly Rule objects (see src/Rule/*). This is the M5 primitive; method-parameter #[Valid]
 * interception is M6/web.
 */
interface Validator
{
    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed> the validated subset
     *
     * @throws ValidationException
     */
    public function validate(array $data, array $rules): array;
}
