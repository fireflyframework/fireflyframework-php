<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Callback;

use Firefly\Orchestration\Core\ExecutionContext;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs lifecycle callbacks. A callback can never change the outcome it is told about: a failure is logged with
 * the execution's correlation id and the next callback still runs. Order: synchronous ones first by
 * DESCENDING priority (declaration order breaks ties), then async ones the same way.
 */
final class CallbackInvoker
{
    public function __construct(
        private readonly Container $container,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  list<CallbackDefinition>  $callbacks
     */
    public function invoke(array $callbacks, ExecutionContext $context, mixed $result = null, ?Throwable $error = null, ?string $stepId = null): void
    {
        $eligible = array_values(array_filter(
            $callbacks,
            static fn (CallbackDefinition $callback): bool => $callback->matchesError($error) && $callback->matchesStep($stepId),
        ));
        usort($eligible, static fn (CallbackDefinition $a, CallbackDefinition $b): int => [$a->async, -$a->priority] <=> [$b->async, -$b->priority]);

        foreach ($eligible as $callback) {
            try {
                $this->call($callback, $context, $result, $error);
            } catch (Throwable $failure) {
                $this->logger->warning("Orchestration callback {$callback->describe()} for [{$context->executionName}] failed: {$failure->getMessage()}", [
                    'exception' => $failure,
                    'correlationId' => $context->correlationId,
                ]);
            }
        }
    }

    /**
     * @param  list<CallbackDefinition>  $callbacks
     */
    public function suppresses(array $callbacks, Throwable $error): bool
    {
        foreach ($callbacks as $callback) {
            if ($callback->suppressError && $callback->matchesError($error)) {
                return true;
            }
        }

        return false;
    }

    private function call(CallbackDefinition $callback, ExecutionContext $context, mixed $result, ?Throwable $error): void
    {
        if ($callback->closure !== null) {
            ($callback->closure)($context, $error ?? $result);

            return;
        }

        $bean = $this->container->make((string) $callback->class);
        $method = (string) $callback->method;
        $arguments = array_map(static fn (string $kind): mixed => match ($kind) {
            CallbackDefinition::CONTEXT => $context,
            CallbackDefinition::ERROR => $error,
            default => $result,
        }, $callback->parameters);

        if (is_object($bean)) {
            $bean->{$method}(...$arguments);
        }
    }
}
