<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Validation;

/**
 * OPT-IN contract a command or query implements to be validated by the bus pipeline over the shipped Validator port.
 * A message that does not implement this is not validated (the validate stage is a no-op for it). Richer
 * attribute-driven validation via M6-web's ConstraintScanner/#[Valid] is a later enhancement (design §5.1, known-latent).
 */
interface Validatable
{
    /**
     * @return array<string,mixed>
     */
    public function validationData(): array;

    /**
     * @return array<string,mixed>
     */
    public function validationRules(): array;
}
