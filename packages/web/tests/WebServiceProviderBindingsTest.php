<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Web\Dispatch\ArgumentResolver;
use Firefly\Web\Dispatch\ControllerDispatcher;
use Firefly\Web\Dispatch\ResponseFactory;
use Firefly\Web\Error\ErrorPageRenderer;
use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Route\RouteManifest;
use Firefly\Web\WebServiceProvider;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;

it('binds every real port its passes and dispatch depend on (M4 bug-7/8/9 lesson)', function () {
    $app = fireflyApplication(['firefly' => []], [WebServiceProvider::class], needs: ['validation', 'http']);

    foreach ([MessageConverterRegistry::class, ArgumentResolver::class, ResponseFactory::class, ExceptionHandlerRegistry::class, ControllerDispatcher::class, RouteManifest::class] as $abstract) {
        expect($app->bound($abstract))->toBeTrue("expected {$abstract} to be bound");
    }
});

it('is idempotent across a simulated second registration (Octane-reset safe)', function () {
    $app = fireflyApplication(['firefly' => []], [WebServiceProvider::class], needs: ['validation', 'http']);
    $first = $app->make(MessageConverterRegistry::class);

    // A second provider instance (as Octane re-registration would do) must not rebind.
    (new WebServiceProvider($app))->register();

    expect($app->make(MessageConverterRegistry::class))->toBe($first);
});

it('resolves the ErrorPageRenderer in a container with no view service, and still renders the built-in page', function () {
    // A fresh Application aliases the view contract to `view` before any provider registers that service, so
    // bound() answers true for a factory that cannot be made. The renderer's view lookup is lazy precisely so a
    // missing or broken view layer cannot break the thing that explains failures — and a boot that resolves the
    // renderer eagerly (firefly/security's entry point bean does) must not die on the alias.
    $app = fireflyApplication(['firefly' => []], [WebServiceProvider::class], needs: ['validation', 'http']);

    expect($app->bound(ViewFactory::class))->toBeTrue()
        ->and($app->bound('view'))->toBeFalse();

    $response = $app->make(ErrorPageRenderer::class)->render(
        new AuthenticationException('Authentication is required.'),
        Request::create('/reports', 'GET', server: ['HTTP_ACCEPT' => 'text/html']),
    );

    expect($response->getStatusCode())->toBe(401)
        ->and((string) $response->headers->get('Content-Type'))->toContain('text/html')
        ->and((string) $response->getContent())->toContain('401');
});
