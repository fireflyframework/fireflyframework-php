<?php

declare(strict_types=1);

namespace Firefly\Validation;

use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Validation\Constraint\ConstraintDescriptor;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The richer validation port — Spring's SmartValidator beside its Validator.
 *
 * Validator::validate() takes raw Laravel rules and can only report what Laravel reports: a humanised
 * sentence per failed rule, keyed by Laravel's attribute. Bean Validation knows more — which #[Constraint]
 * attribute contributed each rule, what sentence it publishes, how the client spelled the field — and that
 * knowledge is compiled into the manifest beside the rules (ConstraintManifest::constraintsFor()). Adding it
 * as a third parameter to validate() would break every custom Validator an application bound, so it is a
 * second interface: BeanValidator hands the descriptors to a port that implements this one and falls back
 * to validate() for one that does not, which keeps a custom port exactly as it was.
 */
interface SmartValidator extends Validator
{
    /**
     * @param  array<string,mixed>  $data
     * @param  array<string, list<string|ValidationRule>>  $rules
     * @param  array<string, list<ConstraintDescriptor>>  $constraints  per property path, the constraints that contributed its rules
     * @return array<string,mixed> the validated subset
     *
     * @throws ValidationException carrying one FieldError per violated constraint, worded by the configured MessageStyle
     */
    public function validateConstraints(array $data, array $rules, array $constraints): array;
}
