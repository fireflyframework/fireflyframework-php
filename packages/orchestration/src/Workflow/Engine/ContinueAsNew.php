<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Engine;

/**
 * Returned by a workflow step to end this execution COMPLETED and start a fresh one of the same workflow with
 * `input` (Temporal's continue-as-new): the way a long-lived, looping workflow keeps its history bounded. The
 * finished execution's variable `continuedAs` names the new correlation id.
 */
final readonly class ContinueAsNew
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(public array $input = []) {}
}
