<?php

declare(strict_types=1);

namespace Firefly\Web;

use Firefly\Config\Config;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Context\Scan\AppScan;
use Firefly\Kernel\Exception\FireflyException;
use Firefly\Validation\Constraint\BeanValidator;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\Constraint\ConstraintManifestCompiler;
use Firefly\Validation\Validator;
use Firefly\Web\Dispatch\ArgumentResolver;
use Firefly\Web\Dispatch\ControllerDispatcher;
use Firefly\Web\Dispatch\ResponseFactory;
use Firefly\Web\Dispatch\RouteWiringPass;
use Firefly\Web\Error\ErrorPageRenderer;
use Firefly\Web\Error\ErrorPageSettings;
use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Firefly\Web\Filter\FilterChainRegistrar;
use Firefly\Web\Http\JsonMessageConverter;
use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\Route\RouteScanner;
use Firefly\Web\Security\AllowAllControllerSecurityGuard;
use Firefly\Web\Security\ControllerSecurityGuard;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
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
        if (! $this->app->bound(ErrorPageSettings::class)) {
            $this->app->singleton(ErrorPageSettings::class, static fn (Container $app): ErrorPageSettings => ErrorPageSettings::fromConfig($app->make(Config::class)));
        }

        if (! $this->app->bound(ErrorPageRenderer::class)) {
            $this->app->singleton(ErrorPageRenderer::class, static function (Container $app): ErrorPageRenderer {
                // base_path() is what turns an absolute file name into `app/Http/OrderController.php` in the
                // trace. Resolved through the container rather than through the global helper so the
                // renderer stays constructible in a test that never booted a Laravel application.
                $base = $app instanceof Application ? $app->basePath() : '';

                // The view factory is optional: an application may have none bound, and the built-in page
                // needs none. It is resolved lazily so a broken view layer cannot break the renderer that
                // exists to explain broken things.
                $views = $app->bound(ViewFactory::class) ? $app->make(ViewFactory::class) : null;

                return new ErrorPageRenderer($app->make(ErrorPageSettings::class), $base, $views);
            });
        }

        if (! $this->app->bound(MessageConverterRegistry::class)) {
            $this->app->singleton(MessageConverterRegistry::class, static fn (): MessageConverterRegistry => new MessageConverterRegistry([new JsonMessageConverter]));
        }

        if (! $this->app->bound(ConstraintManifest::class)) {
            $this->app->singleton(ConstraintManifest::class, static function (Application $app): ConstraintManifest {
                if (($file = AppScan::cachedFile($app, AppScan::CONSTRAINTS)) !== null) {
                    return ConstraintManifest::load($file);
                }

                $paths = AppScan::paths($app);
                if ($paths === []) {
                    return new ConstraintManifest([]);
                }

                // Validation compiles from an explicit class list, not a directory walk, so the in-process
                // fallback enumerates the PSR-4 roots the same way ManifestCacheWriter does.
                return ConstraintManifest::fromArray((new ConstraintManifestCompiler)->toArray(AppScan::classes($paths)));
            });
        }

        if (! $this->app->bound(BeanValidator::class)) {
            $this->app->singleton(BeanValidator::class, static fn (Application $app): BeanValidator => new BeanValidator($app->make(Validator::class), $app->make(ConstraintManifest::class)));
        }

        if (! $this->app->bound(ArgumentResolver::class)) {
            $this->app->singleton(ArgumentResolver::class, static fn (Application $app): ArgumentResolver => new ArgumentResolver($app->make(MessageConverterRegistry::class), $app->make(BeanValidator::class)));
        }

        if (! $this->app->bound(ResponseFactory::class)) {
            // The view factory is optional: illuminate/view ships with laravel/framework but is not a
            // dependency of firefly/web, so a JSON-only deployment (or a unit test) resolves null and the
            // HTML branch fails loud instead of rendering an empty body.
            //
            // The probe is the CONCRETE 'view' key, not the contract. Container::bound() answers true for a
            // mere ALIAS, and Laravel aliases Illuminate\Contracts\View\Factory => 'view' in
            // registerCoreContainerAliases() whether or not ViewServiceProvider ever registered anything —
            // so probing the contract reports a view factory in a bare testbench and then explodes with
            // "Target class [view] does not exist" on make().
            $this->app->singleton(ResponseFactory::class, static function (Application $app): ResponseFactory {
                $views = $app->bound('view') ? $app->make(ViewFactory::class) : null;

                return new ResponseFactory($app->make(MessageConverterRegistry::class), $views);
            });
        }

        // #[ControllerAdvice] / #[ExceptionHandler] used to be dead in every real boot: RouteScanner::
        // scanExceptionHandlers() was implemented but called by nothing outside three test base classes, and
        // firefly:cache emitted no artifact, so this registry was always constructed empty. It now resolves
        // like every other Category-B manifest.
        if (! $this->app->bound(ExceptionHandlerRegistry::class)) {
            $this->app->singleton(ExceptionHandlerRegistry::class, static function (Application $app): ExceptionHandlerRegistry {
                if (($file = AppScan::cachedFile($app, AppScan::EXCEPTION_HANDLERS)) !== null) {
                    return ExceptionHandlerRegistry::load($file);
                }

                $paths = AppScan::paths($app);

                return new ExceptionHandlerRegistry($paths === [] ? [] : (new RouteScanner)->scanExceptionHandlers($paths));
            });
        }

        // The M11 dispatch-time method-security seam (§4.6.2): a no-op default so #[PreAuthorize] enforcement
        // is opt-in. bound()-guarded so firefly/security's SecurityWiringPass (T17/T20) — which binds the real
        // guard during boot, AFTER this register() has already run — always wins, first-one-wins style, same
        // idiom as every other binding in this method.
        if (! $this->app->bound(ControllerSecurityGuard::class)) {
            $this->app->singleton(ControllerSecurityGuard::class, static fn (): ControllerSecurityGuard => new AllowAllControllerSecurityGuard);
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
            $this->app->singleton(RouteManifest::class, static function (Application $app): RouteManifest {
                if (($file = AppScan::cachedFile($app, AppScan::ROUTES)) !== null) {
                    return RouteManifest::load($file);
                }

                $paths = AppScan::paths($app);

                return new RouteManifest($paths === [] ? [] : (new RouteScanner)->scan($paths));
            });
        }
    }

    /**
     * One renderable answering in two shapes: a page for a browser, a problem document for everything else.
     *
     * The order is the whole of it. A browser that names `text/html` gets the HTML page — which is what
     * fixes a person clicking a stale link and being shown a raw JSON blob, the behaviour every
     * FireflyException had. Everything else keeps the previous rule exactly: a FireflyException, or a
     * request that wants JSON, renders as problem+json. A throwable that is NEITHER — an unrouted URL hit by
     * a client that asked for neither — still falls through to Laravel's handler, because inventing a
     * response shape for a caller that expressed no preference is not this package's decision to make.
     */
    private function registerProblemDetailsRenderable(): void
    {
        $this->app->afterResolving(ExceptionHandlerContract::class, function (object $handler): void {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(function (Throwable $e, Request $request) {
                $page = $this->app->make(ErrorPageRenderer::class);

                if ($page->handles($request)) {
                    return $page->render($e, $request);
                }

                if ($e instanceof FireflyException || $request->expectsJson() || $page->forcesJson($request)) {
                    return $this->app->make(ProblemDetailsRenderer::class)->render($e, $request);
                }

                return null;
            });
        });
    }
}
