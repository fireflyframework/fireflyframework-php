<?php

declare(strict_types=1);

namespace Firefly\Web;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Kernel\Exception\FireflyException;
use Firefly\Validation\Constraint\BeanValidator;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\Validator;
use Firefly\Web\Dispatch\ArgumentResolver;
use Firefly\Web\Dispatch\ControllerDispatcher;
use Firefly\Web\Dispatch\ResponseFactory;
use Firefly\Web\Dispatch\RouteWiringPass;
use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Firefly\Web\Filter\FilterChainRegistrar;
use Firefly\Web\Http\JsonMessageConverter;
use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Throwable;

/**
 * The discovered web provider. register() binds every REAL port its passes and dispatch depend on (M4
 * bug-7/8/9 lesson — a provider that only contributes passes and binds nothing is a shell), each behind a
 * bound() guard for Octane idempotency, then calls parent::register() (which makes the kernel and adds the
 * passes). passes() contributes the two WiringPasses (routes first, then the filter chain).
 */
final class WebServiceProvider extends FireflyServiceProvider
{
    public function register(): void
    {
        $this->registerBindings();
        $this->registerProblemDetailsRenderable();

        parent::register();
    }

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new RouteWiringPass, new FilterChainRegistrar];
    }

    private function registerBindings(): void
    {
        if (! $this->app->bound(MessageConverterRegistry::class)) {
            $this->app->singleton(MessageConverterRegistry::class, static fn (): MessageConverterRegistry => new MessageConverterRegistry([new JsonMessageConverter]));
        }

        if (! $this->app->bound(ConstraintManifest::class)) {
            $this->app->singleton(ConstraintManifest::class, static fn (): ConstraintManifest => new ConstraintManifest([]));
        }

        if (! $this->app->bound(BeanValidator::class)) {
            $this->app->singleton(BeanValidator::class, static fn (Application $app): BeanValidator => new BeanValidator($app->make(Validator::class), $app->make(ConstraintManifest::class)));
        }

        if (! $this->app->bound(ArgumentResolver::class)) {
            $this->app->singleton(ArgumentResolver::class, static fn (Application $app): ArgumentResolver => new ArgumentResolver($app->make(MessageConverterRegistry::class), $app->make(BeanValidator::class)));
        }

        if (! $this->app->bound(ResponseFactory::class)) {
            $this->app->singleton(ResponseFactory::class, static fn (Application $app): ResponseFactory => new ResponseFactory($app->make(MessageConverterRegistry::class)));
        }

        if (! $this->app->bound(ExceptionHandlerRegistry::class)) {
            $this->app->singleton(ExceptionHandlerRegistry::class, static fn (): ExceptionHandlerRegistry => new ExceptionHandlerRegistry([]));
        }

        if (! $this->app->bound(ControllerDispatcher::class)) {
            // Typed as the CONCRETE container (not the Application contract the sibling closures use): the
            // dispatch path holds Illuminate\Container\Container (ArgumentResolver::resolve's shipped signature),
            // and Laravel always passes the concrete container into a binding closure, so this narrows honestly.
            $this->app->singleton(ControllerDispatcher::class, static fn (Container $app): ControllerDispatcher => new ControllerDispatcher(
                $app,
                $app->make(ArgumentResolver::class),
                $app->make(ResponseFactory::class),
                $app->make(ExceptionHandlerRegistry::class),
            ));
        }

        if (! $this->app->bound(RouteManifest::class)) {
            $this->app->singleton(RouteManifest::class, static fn (): RouteManifest => new RouteManifest([]));
        }
    }

    private function registerProblemDetailsRenderable(): void
    {
        $this->app->afterResolving(ExceptionHandlerContract::class, function (object $handler): void {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(function (Throwable $e, Request $request) {
                if ($e instanceof FireflyException || $request->expectsJson()) {
                    return $this->app->make(ProblemDetailsRenderer::class)->render($e, $request);
                }

                return null;
            });
        });
    }
}
