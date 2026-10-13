<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Argument;

use Firefly\Orchestration\Core\Rows;

/**
 * How one parameter of a step, compensation, participant or callback method receives its value — compiled by
 * OrchestrationScanner from the parameter's type and attribute, so the runtime resolves arguments with no
 * reflection. The first un-annotated parameter is the IMPLICIT one (the step input; for a compensation the
 * step result; for confirm/cancel the try result); a later un-annotated parameter with a default is left to
 * its default; anything else refuses the scan.
 */
final readonly class ParameterBinding
{
    public const string IMPLICIT = 'implicit';

    public const string CONTEXT = 'context';

    public const string INPUT = 'input';

    public const string INPUT_KEY = 'input-key';

    public const string FROM_STEP = 'from-step';

    public const string FROM_COMPENSATION_RESULT = 'from-compensation-result';

    public const string COMPENSATION_ERROR = 'compensation-error';

    public const string FROM_TRY = 'from-try';

    public const string HEADER = 'header';

    public const string HEADERS = 'headers';

    public const string VARIABLE = 'variable';

    public const string VARIABLES = 'variables';

    public const string CORRELATION_ID = 'correlation-id';

    public const string DEFAULT = 'default';

    public function __construct(
        public string $name,
        public string $kind,
        public string $ref = '',
        public bool $required = false,
    ) {}

    /** @return array{name: string, kind: string, ref: string, required: bool} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'kind' => $this->kind, 'ref' => $this->ref, 'required' => $this->required];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(Rows::string($row, 'name'), Rows::string($row, 'kind', self::IMPLICIT), Rows::string($row, 'ref'), Rows::bool($row, 'required'));
    }
}
