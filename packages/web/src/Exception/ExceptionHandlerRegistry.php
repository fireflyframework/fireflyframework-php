<?php

declare(strict_types=1);

namespace Firefly\Web\Exception;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
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

    /**
     * Rehydrate from the compiled exception-handlers.php emitted by ExceptionHandlerManifestCompiler.
     *
     * @param  array<int, array{exceptionClass: string, handlerClass: string, methodName: string, global: bool}>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            static fn (array $row): ExceptionHandlerDescriptor => ExceptionHandlerDescriptor::fromArray($row),
            array_values($data),
        ));
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Exception handler manifest not found at {$path}. Run the exception-handler scan first.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Exception handler manifest at {$path} did not return an array.");
        }

        /** @var array<int, array{exceptionClass: string, handlerClass: string, methodName: string, global: bool}> $data */
        return self::fromArray($data);
    }

    /**
     * @return list<ExceptionHandlerDescriptor>
     */
    public function all(): array
    {
        return $this->handlers;
    }

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
