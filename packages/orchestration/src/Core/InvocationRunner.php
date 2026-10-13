<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

use Firefly\Orchestration\Core\Argument\ArgumentResolver;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Illuminate\Contracts\Container\Container;
use LogicException;

/** Runs one Invocation once — no retry, no timeout (StepInvoker adds those) — then applies #[SetVariable]. */
final class InvocationRunner
{
    public function __construct(
        private readonly Container $container,
        private readonly ArgumentResolver $arguments,
    ) {}

    public function run(Invocation $invocation, ExecutionContext $context, mixed $input, mixed $implicit): mixed
    {
        $result = match (true) {
            $invocation instanceof ClosureInvocation => ($invocation->closure)($implicit, $context),
            $invocation instanceof MethodInvocation => $this->call($invocation, $context, $input, $implicit),
            default => throw new LogicException('Unsupported orchestration invocation '.$invocation::class.'.'),
        };

        $variable = $invocation->setVariable();
        if ($variable !== null) {
            $context->setVariable($variable, $result);
        }

        return $result;
    }

    private function call(MethodInvocation $invocation, ExecutionContext $context, mixed $input, mixed $implicit): mixed
    {
        $bean = $this->container->make($invocation->class);
        if (! is_object($bean) || ! method_exists($bean, $invocation->method)) {
            throw new OrchestrationException("{$invocation->describe()} does not exist; recompile with php artisan firefly:cache.", 'ORCHESTRATION_UNKNOWN_METHOD');
        }

        $method = $invocation->method;

        return $bean->{$method}(...$this->arguments->resolve($invocation, $context, $input, $implicit));
    }
}
