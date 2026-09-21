<?php

declare(strict_types=1);

namespace Firefly\Kernel\Error;

/**
 * A single field-level error, e.g. a failed validation constraint.
 *
 * `constraint` names the DECLARED constraint that failed by its attribute's short name — `NotBlank`, `Size`,
 * `Email`, `Pattern` — the value Spring's FieldError publishes as `code`. It is the member a client branches
 * on when the sentence in `message` is written for a person; it is null for an error nothing declared (a
 * hand-built FieldError, a rule reached through the `validate()` primitive). `code` stays the application's
 * own semantic code, as it always was; the two answer different questions.
 */
final readonly class FieldError
{
    public function __construct(
        public string $field,
        public string $message,
        public ?string $code = null,
        public mixed $rejectedValue = null,
        public ?string $constraint = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'field' => $this->field,
            'message' => $this->message,
        ];

        if ($this->code !== null) {
            $data['code'] = $this->code;
        }

        if ($this->constraint !== null) {
            $data['constraint'] = $this->constraint;
        }

        if ($this->rejectedValue !== null) {
            $data['rejectedValue'] = $this->rejectedValue;
        }

        return $data;
    }
}
