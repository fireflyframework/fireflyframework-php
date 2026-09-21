<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Login;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Security\Web\Settings\FormLoginSettings;
use Firefly\Security\Web\Settings\RememberMeSettings;
use Firefly\Web\Error\ErrorPageSettings;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Psr\Log\LoggerInterface;

/**
 * Mounts GET {login_page} on the Router when form login is on (the ActuatorRouteRegistrar precedent: a route
 * whose path is configuration cannot be an attribute route). Only the PAGE is a route — the POST is handled
 * by FormLoginFilter before routing, as Spring does, so it needs no rule, no CSRF group and no controller.
 * Order 70: after the attribute routes (RouteWiringPass), before SessionSecurityBootstrap (90) installs the
 * session middleware whose RouteMatched listener strips it from this route too, and before the filter chain
 * (100). HttpSecurityFilter permits the page by path whatever the URL rules say, so `*` → authenticated does
 * not lock a visitor out of the one page that lets them in.
 *
 * A LOGIN PAGE THE APPLICATION ALREADY OWNS IS LEFT ALONE. Laravel's RouteCollection keeps one route per
 * method and URI and the LAST registration wins, and this pass runs after the application's routes — so
 * mounting unconditionally would replace a Breeze/Fortify-style controller, or any #[GetMapping('/login')],
 * the moment `form_login.enabled` went on, with no log, no refusal and no failing test: the framework page
 * would simply appear where the application's used to be. Spring's rule is the one applied instead: a custom
 * login page belongs to the application (formLogin().loginPage() switches the default page generator off),
 * and the framework generates a page only when nobody else answers that address. So when a GET route at
 * {login_page} exists by the time this pass runs — with or without a domain, attribute route or routes file
 * — nothing is mounted and `firefly.security.login` is not a named route. Everything else still works
 * unchanged: HttpSecurityFilter permits the page by path, the entry point redirects to it, and FormLoginFilter
 * answers the POST before routing, so the application's form needs only to post the session token to
 * `login_processing_url`. The rule is documented on the login_page row of docs/modules/security.md and in the
 * skeleton, and pinned by ApplicationLoginPageTest.
 *
 * The action is built per request from the container, not resolved as a bean: its settings are master-gated
 * beans that exist exactly when this route does, and the view factory and the logger are optional — resolved
 * the way the error-page renderer resolves the view factory, because a fresh Application aliases both
 * contracts before any provider binds the service, so bound() alone answers true in a bare container and the
 * make() throws. The logger is what makes a `view` that does not render VISIBLE (LoginPageAction warns on
 * every fallback, naming the view); without one the page still falls back, silently, as it must in a bare
 * container.
 */
final class LoginRouteRegistrar implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 70;
    }

    public function run(BootContext $context): void
    {
        $config = $context->config;
        if (! $config->bool('firefly.security.enabled', false) || ! $config->bool('firefly.security.form_login.enabled', false)) {
            return;
        }

        $container = $context->container;
        $settings = FormLoginSettings::fromConfig($config);

        /** @var Router $router */
        $router = $container->make('router');
        $page = FormLoginSettings::path($settings->loginPage);
        if (self::applicationOwns($router, $page)) {
            return;
        }

        $router->get($page, static function (Request $request) use ($container): Response {
            /** @var FormLoginSettings $formLogin */
            $formLogin = $container->make(FormLoginSettings::class);
            /** @var RememberMeSettings $rememberMe */
            $rememberMe = $container->make(RememberMeSettings::class);
            /** @var ErrorPageSettings $pages */
            $pages = $container->make(ErrorPageSettings::class);

            return (new LoginPageAction(
                $formLogin,
                $rememberMe,
                $pages,
                self::optional($container, ViewFactory::class),
                self::optional($container, LoggerInterface::class),
            ))($request);
        })->name('firefly.security.login');
    }

    /**
     * Whether a GET route at the login page's path is already registered — the application's own page, which
     * this pass then must not replace. Compared BY PATH the way FormLoginSettings compares every URL, so a
     * route declared as `login`, `/login` or `/login/` is the same address as a `login_page` of `/login?x`.
     */
    private static function applicationOwns(Router $router, string $page): bool
    {
        foreach ($router->getRoutes()->get('GET') as $route) {
            if (FormLoginSettings::path('/'.$route->uri()) === $page) {
                return true;
            }
        }

        return false;
    }

    /**
     * A service the page can do without: null when the container has no binding for it, and null when the
     * binding is an alias nothing ever filled in (the bare-container case above) rather than an exception.
     *
     * @template T of object
     *
     * @param  class-string<T>  $abstract
     * @return T|null
     */
    private static function optional(Container $container, string $abstract): ?object
    {
        if (! $container->bound($abstract)) {
            return null;
        }

        try {
            $service = $container->make($abstract);
        } catch (BindingResolutionException) {
            return null;
        }

        return $service instanceof $abstract ? $service : null;
    }
}
