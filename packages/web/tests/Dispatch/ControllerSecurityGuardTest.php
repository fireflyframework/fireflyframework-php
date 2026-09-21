<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Web\Dispatch\ControllerDispatcher;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Security\ControllerSecurityGuard;
use Firefly\Web\Tests\Fixtures\Security\GuardedController;
use Firefly\Web\Tests\Fixtures\Security\RecordingSecurityGuard;
use Firefly\Web\WebServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Build a minimal app with the REAL WebServiceProvider registered (mirrors WebServiceProviderBindingsTest), so
 * ControllerDispatcher resolves its true collaborators (ArgumentResolver / ResponseFactory /
 * ExceptionHandlerRegistry). The 'validation'/'http' needs supply the Validator and HTTP-kernel bindings a real
 * host app would provide — without them, resolving ControllerDispatcher eagerly walks ArgumentResolver =>
 * BeanValidator => Validator, and FilterChainRegistrar (run at boot) resolves the HTTP kernel contract; a bare
 * app with neither bound fails with "Target [...] is not instantiable" before a single assertion runs — exactly
 * the gap PackageBootsTest.php and ArgumentResolverTest.php already work around by requesting the same needs.
 */
function guardTestApp(): Application
{
    return fireflyApplication(['firefly' => []], [WebServiceProvider::class], needs: ['validation', 'http']);
}

function descriptorFor(string $method): RouteDescriptor
{
    return new RouteDescriptor('GET', '/guarded', GuardedController::class, $method, 200, null, []);
}

it('invokes the guard with class/method/args before the controller runs', function () {
    $app = guardTestApp();
    $guard = new RecordingSecurityGuard;
    $app->instance(ControllerSecurityGuard::class, $guard);
    $controller = new GuardedController;
    $app->instance(GuardedController::class, $controller);

    $closure = $app->make(ControllerDispatcher::class)->actionFor(descriptorFor('index'));
    $closure(Request::create('/guarded', 'GET'));

    expect($guard->calls)->toBe([['class' => GuardedController::class, 'method' => 'index', 'args' => []]])
        ->and($controller->ran)->toBeTrue();
});

it('a denying guard stops the controller and surfaces a 403', function () {
    $app = guardTestApp();
    $app->instance(ControllerSecurityGuard::class, new RecordingSecurityGuard(denyMethod: 'denied'));
    $controller = new GuardedController;
    $app->instance(GuardedController::class, $controller);

    $closure = $app->make(ControllerDispatcher::class)->actionFor(descriptorFor('denied'));

    expect(fn () => $closure(Request::create('/guarded', 'GET')))->toThrow(AuthorizationException::class)
        ->and($controller->ran)->toBeFalse();
});

it('hands the handler result to the guard after the call and returns what the guard answers', function () {
    $app = guardTestApp();
    $guard = new RecordingSecurityGuard(replaceResultWith: ['filtered' => true]);
    $app->instance(ControllerSecurityGuard::class, $guard);
    $app->instance(GuardedController::class, new GuardedController);

    $closure = $app->make(ControllerDispatcher::class)->actionFor(descriptorFor('index'));
    $response = $closure(Request::create('/guarded', 'GET'));

    expect($guard->afterCalls)->toBe([['class' => GuardedController::class, 'method' => 'index', 'args' => []]])
        ->and($response)->toBeInstanceOf(Response::class)
        ->and($response instanceof Response ? (string) $response->getContent() : null)->toBe('{"filtered":true}');
});
