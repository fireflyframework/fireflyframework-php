<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Validation;

use Firefly\Validation\Validator;

/**
 * The bus pipeline's validate stage. REUSES the shipped Validator port (packages/validation) rather than porting
 * pyfly's pydantic validator. Opt-in: no-op unless the message implements Validatable AND a Validator is bound
 * (absent the validation package, the injected Validator is null and validation is skipped). On a rule violation the
 * shipped Validator throws the kernel ValidationException, which the bus wraps category-preservingly (422 stays 422).
 * One collaborator serves both the command and query buses — command/query validation over Validatable is identical.
 */
final class MessageValidator
{
    public function __construct(private readonly ?Validator $validator = null) {}

    public function validate(object $message): void
    {
        if ($message instanceof Validatable && $this->validator !== null) {
            $this->validator->validate($message->validationData(), $message->validationRules());
        }
    }
}
