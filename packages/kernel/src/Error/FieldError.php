<?php

declare(strict_types=1);

namespace Firefly\Kernel\Error;

/**
 * A single field-level error, e.g. a failed validation constraint.
 */
final readonly class FieldError
{
    public function __construct(
        public string $field,
        public string $message,
        public ?string $code = null,
        public mixed $rejectedValue = null,
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

        if ($this->rejectedValue !== null) {
            $data['rejectedValue'] = $this->rejectedValue;
        }

        return $data;
    }
}
