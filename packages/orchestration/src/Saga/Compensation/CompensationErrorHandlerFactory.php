<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Compensation;

use Closure;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * Turns `firefly.orchestration.saga.compensation-error-handler` into a handler (jafly
 * CompensationErrorHandlerFactory, as an instance instead of a static registry): one of the built-in names,
 * a name registered with register(), or the class name of an application CompensationErrorHandler resolved
 * from the container. Anything else refuses the boot, listing what exists.
 */
final class CompensationErrorHandlerFactory
{
    /** @var array<string, Closure(): CompensationErrorHandler> */
    private array $factories;

    public function __construct(
        private readonly Container $container,
        LoggerInterface $logger,
    ) {
        $this->factories = [
            'default' => static fn (): CompensationErrorHandler => new DefaultCompensationErrorHandler,
            'fail-fast' => static fn (): CompensationErrorHandler => new FailFastCompensationErrorHandler,
            'log-and-continue' => static fn (): CompensationErrorHandler => new LogAndContinueCompensationErrorHandler($logger),
            'retry' => static fn (): CompensationErrorHandler => new RetryWithBackoffCompensationErrorHandler(3),
            'robust' => static fn (): CompensationErrorHandler => new RobustCompensationErrorHandler($logger, 3),
        ];
    }

    /**
     * @param  Closure(): CompensationErrorHandler  $factory
     */
    public function register(string $name, Closure $factory): void
    {
        $this->factories[$name] = $factory;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map('strval', array_keys($this->factories));
    }

    public function get(string $name): CompensationErrorHandler
    {
        if (isset($this->factories[$name])) {
            return ($this->factories[$name])();
        }

        if (class_exists($name) && is_a($name, CompensationErrorHandler::class, true)) {
            $handler = $this->container->make($name);
            if ($handler instanceof CompensationErrorHandler) {
                return $handler;
            }
        }

        throw new ConfigurationException("Configuration key [firefly.orchestration.saga.compensation-error-handler] names [{$name}], which is neither one of ".implode(', ', $this->names()).' nor a class implementing '.CompensationErrorHandler::class.'.');
    }
}
