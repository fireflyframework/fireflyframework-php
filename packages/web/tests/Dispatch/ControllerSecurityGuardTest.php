<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Validation\IlluminateValidator;
use Firefly\Validation\Validator;
use Firefly\Web\Dispatch\ControllerDispatcher;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Security\ControllerSecurityGuard;
use Firefly\Web\Tests\Fixtures\Security\GuardedController;
use Firefly\Web\Tests\Fixtures\Security\RecordingSecurityGuard;
use Firefly\Web\WebServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

/**
 * Build a minimal app with the REAL WebServiceProvider registered (mirrors WebServiceProviderBindingsTest), so
 * ControllerDispatcher resolves its true collaborators (ArgumentResolver / ResponseFactory /
 * ExceptionHandlerRegistry). A bare Testbench TestCase would NOT register WebServiceProvider, so
 * $app->make(ControllerDispatcher::class) would fail to resolve those deps — hence the explicit registration.
 *
 * DEVIATION FROM THE BRIEF (noted, no production code changed for it): the brief's guardTestApp() omits a
 * Validator::class binding. Resolving ControllerDispatcher here eagerly walks ArgumentResolver => BeanValidator
 * => Validator, and a bare Application (no boot(), no compiled auto-config manifests) never binds that port —
 * exactly the gap PackageBootsTest.php and ArgumentResolverTest.php already work around by binding the shipped
 * IlluminateValidator adapter directly. Without this line every test here fails with "Target
 * [Firefly\Validation\Validator] is not instantiable", not because of anything this task's dispatcher change
 * did.
 */
function guardTestApp(): Application
{
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => []]));
    $app->instance(Validator::class, new IlluminateValidator(new IlluminateFactory(new Translator(new ArrayLoader, 'en'))));
    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new WebServiceProvider($app));

    return $app;
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
