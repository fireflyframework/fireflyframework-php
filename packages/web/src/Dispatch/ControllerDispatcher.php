<?php

declare(strict_types=1);

namespace Firefly\Web\Dispatch;

use Closure;
use Firefly\Kernel\Exception\FireflyException;
use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Firefly\Web\Route\RouteDescriptor;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Throwable;

/**
 * Builds the per-route dispatch closure. Per request: resolve the controller bean (singleton, DI already
 * wired), bind arguments (ArgumentResolver — which triggers #[Valid]), invoke the handler, and
 * content-negotiate the return (ResponseFactory). A thrown exception is offered to the
 * ExceptionHandlerRegistry (controller-local beats global); with no match it propagates to Laravel's
 * exception handler, where the RFC-7807 renderable renders it — so 404/422 problem+json flows through the
 * real HTTP pipeline, not a bespoke catch here.
 */
final class ControllerDispatcher
{
    public function __construct(
        private readonly Container $container,
        private readonly ArgumentResolver $resolver,
        private readonly ResponseFactory $responseFactory,
        private readonly ExceptionHandlerRegistry $handlers,
    ) {}

    public function actionFor(RouteDescriptor $descriptor): Closure
    {
        return function (Request $request) use ($descriptor) {
            try {
                $controller = $this->container->make($descriptor->controllerClass);
                $args = $this->resolver->resolve($descriptor->bindings, $request, $this->container);
                // controllerClass is a plain string (not class-string), so make() returns mixed; narrow it to
                // an object for the dynamic method call (mirrors RegisterEventListenersPass's /** @var object */).
                /** @var object $controller */
                $result = $controller->{$descriptor->methodName}(...$args);
            } catch (Throwable $e) {
                $handler = $this->handlers->resolve($e, $descriptor->controllerClass);
                if ($handler === null) {
                    throw $e;
                }
                $bean = $this->container->make($handler->handlerClass);
                /** @var object $bean */
                $result = $bean->{$handler->methodName}($e);

                // A matched #[ExceptionHandler] renders its return value at the EXCEPTION's status (not the
                // route's 200/201). If the handler returned a Response, ResponseFactory passes it through and
                // the status override is ignored. A non-Firefly Throwable falls back to the descriptor status.
                $status = $e instanceof FireflyException ? $e->httpStatus() : $descriptor->status;

                return $this->responseFactory->make($result, $descriptor, $request, $status);
            }

            return $this->responseFactory->make($result, $descriptor, $request);
        };
    }
}
