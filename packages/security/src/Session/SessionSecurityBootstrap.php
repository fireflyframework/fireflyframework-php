<?php

declare(strict_types=1);

namespace Firefly\Security\Session;

use Firefly\Config\Config;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;

/**
 * Makes the Laravel session available to the security filters.
 *
 * WHY THIS IS A BOOT PASS AND NOT A MIDDLEWARE GROUP. Firefly filters are GLOBAL kernel middleware
 * (FilterChainRegistrar pushes them at WiringPasses order 100), and Laravel starts the session in the `web`
 * ROUTE group, which runs after every global middleware — so a filter at -94 asking `$request->session()`
 * would find none. When session security is on, this pass (order 90, after every route registrar and before
 * the filter chain) pushes EncryptCookies, AddQueuedCookiesToResponse and StartSession onto the global stack,
 * where they run ahead of the filters and wrap them on the way out.
 *
 * WHY IT ALSO STRIPS THE SAME THREE CLASSES FROM EVERY ROUTE. Laravel does not de-duplicate a class that
 * appears both globally and on a route, and a second EncryptCookies pass DECRYPTS ALREADY-DECRYPTED COOKIES:
 * every cookie fails, is set to null, and the second StartSession mints a fresh session — the login is lost on
 * exactly the routes that carried the `web` group or, like the admin dashboard, the classes themselves. So
 * the three are removed from the `web` group and excluded (Route::withoutMiddleware) on every route registered
 * so far. ShareErrorsFromSession and PreventRequestForgery stay where they were: they only need the session to
 * have been started, which it now has, earlier.
 *
 * A session driver is required: form login cannot work without one, so its absence is a boot refusal with
 * the key to set, rather than a 403 on the first login attempt.
 */
final class SessionSecurityBootstrap implements BootPass
{
    public const array MIDDLEWARE = [EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class];

    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 90;
    }

    public function run(BootContext $context): void
    {
        $config = $context->config;
        if (! $config->bool('firefly.security.enabled', false) || ! (new SessionSecuritySettings($config))->enabled()) {
            return;
        }

        self::assertSessionDriver($config);

        $kernel = $this->resolveHttpKernel($context);
        if (! $kernel instanceof FoundationHttpKernel) {
            return;
        }

        foreach (self::MIDDLEWARE as $middleware) {
            if (! $kernel->hasMiddleware($middleware)) {
                $kernel->pushMiddleware($middleware);
            }
        }

        /** @var Router $router */
        $router = $context->container->make('router');
        foreach (self::MIDDLEWARE as $middleware) {
            if ($router->hasMiddlewareGroup('web')) {
                $router->removeMiddlewareFromGroup('web', $middleware);
            }
        }
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $route->withoutMiddleware(self::MIDDLEWARE);
        }
    }

    public static function assertSessionDriver(Config $config): void
    {
        /** @var mixed $driver */
        $driver = $config->get('session.driver');
        if (! is_string($driver) || $driver === '') {
            throw new ConfigurationException(
                'Session-backed security (form login, remember-me, http_basic.session or firefly.security.session.enabled) '
                .'needs a session driver: set SESSION_DRIVER (session.driver), or turn those mechanisms off.'
            );
        }
    }

    /** Typed `object` so the instanceof narrowing above is real — see FilterChainRegistrar::resolveHttpKernel(). */
    private function resolveHttpKernel(BootContext $context): object
    {
        return $context->container->make(HttpKernelContract::class);
    }
}
