<?php

declare(strict_types=1);

namespace Firefly\Web\Exception;

use Throwable;

/**
 * Resolves the handler for a thrown exception: a controller-local handler (global=false, handlerClass ===
 * the dispatching controller) wins over any global one (pyfly parity); within the chosen scope, the
 * most-derived exceptionClass that still matches wins (most-specific-first by hierarchy). Reflection-free —
 * hierarchy is compared with is_a / is_subclass_of over class-strings, never runtime class introspection.
 */
final class ExceptionHandlerRegistry
{
    /**
     * @param  list<ExceptionHandlerDescriptor>  $handlers
     */
    public function __construct(private readonly array $handlers) {}

    public function resolve(Throwable $e, ?string $controllerClass = null): ?ExceptionHandlerDescriptor
    {
        $matching = array_values(array_filter(
            $this->handlers,
            static fn (ExceptionHandlerDescriptor $h): bool => is_a($e, $h->exceptionClass),
        ));

        $local = array_values(array_filter(
            $matching,
            static fn (ExceptionHandlerDescriptor $h): bool => ! $h->global && $h->handlerClass === $controllerClass,
        ));

        $scope = $local !== [] ? $local : array_values(array_filter($matching, static fn ($h): bool => $h->global || $h->handlerClass === $controllerClass));

        return $this->mostSpecific($scope);
    }

    /**
     * @param  list<ExceptionHandlerDescriptor>  $handlers
     */
    private function mostSpecific(array $handlers): ?ExceptionHandlerDescriptor
    {
        $best = null;
        foreach ($handlers as $handler) {
            if ($best === null || is_subclass_of($handler->exceptionClass, $best->exceptionClass)) {
                $best = $handler;
            }
        }

        return $best;
    }
}
