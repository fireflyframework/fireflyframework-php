<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Validation;

/** One finding of OrchestrationValidator: an ERROR refuses the boot, a WARNING does when fail-on-warning is on. */
final readonly class ValidationIssue
{
    public const string ERROR = 'error';

    public const string WARNING = 'warning';

    public function __construct(
        public string $severity,
        public string $message,
    ) {}
}
