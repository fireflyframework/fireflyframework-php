<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Argument;

use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\MethodInvocation;

/**
 * Resolves a compiled MethodInvocation's parameter plan into NAMED arguments. Named, because a DEFAULT
 * parameter is simply left out — PHP then applies its default — which positional spreading cannot express.
 */
final class ArgumentResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(MethodInvocation $invocation, ExecutionContext $context, mixed $input, mixed $implicit): array
    {
        $arguments = [];
        foreach ($invocation->parameters as $binding) {
            if ($binding->kind === ParameterBinding::DEFAULT) {
                continue;
            }

            $value = $this->value($binding, $context, $input, $implicit);
            if ($value === null && $binding->required) {
                throw new OrchestrationException("Required parameter \${$binding->name} of {$invocation->describe()} resolved to null.", 'ORCHESTRATION_REQUIRED_ARGUMENT', 422);
            }

            $arguments[$binding->name] = $value;
        }

        return $arguments;
    }

    private function value(ParameterBinding $binding, ExecutionContext $context, mixed $input, mixed $implicit): mixed
    {
        return match ($binding->kind) {
            ParameterBinding::IMPLICIT => $implicit,
            ParameterBinding::CONTEXT => $context,
            ParameterBinding::INPUT => $input,
            ParameterBinding::INPUT_KEY => is_array($input) ? ($input[$binding->ref] ?? null) : null,
            ParameterBinding::FROM_STEP => $context->result($binding->ref),
            ParameterBinding::FROM_COMPENSATION_RESULT => $context->compensationResult($binding->ref),
            ParameterBinding::COMPENSATION_ERROR => $context->compensationError($binding->ref),
            ParameterBinding::FROM_TRY => $binding->ref === '' ? $implicit : $context->tryResult($binding->ref),
            ParameterBinding::HEADER => $context->header($binding->ref),
            ParameterBinding::HEADERS => $context->headers(),
            ParameterBinding::VARIABLE => $context->variable($binding->ref),
            ParameterBinding::VARIABLES => $context->variables(),
            ParameterBinding::CORRELATION_ID => $context->correlationId,
            default => throw new OrchestrationException("Unknown parameter binding [{$binding->kind}] for \${$binding->name}; recompile with php artisan firefly:cache.", 'ORCHESTRATION_UNKNOWN_BINDING'),
        };
    }
}
