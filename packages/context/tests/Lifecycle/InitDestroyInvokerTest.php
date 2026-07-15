<?php

declare(strict_types=1);

use Firefly\Context\Lifecycle\InitDestroyInvoker;
use Firefly\Context\Lifecycle\PostConstruct;
use Firefly\Context\Lifecycle\PreDestroy;
use Illuminate\Container\Container;

/**
 * InitDestroyInvoker is exercised through REAL fixture classes (never mocks of our own
 * interfaces), invoked through a REAL Illuminate\Container\Container so $container->call()'s
 * dependency injection is genuinely exercised, not stubbed.
 */

/** A dependency injected into a #[PostConstruct] method's parameters. */
final class InvokerGreeting
{
    public string $text = 'hello';
}

final class InvokerBean
{
    /** @var list<string> */
    public array $calls = [];

    public ?string $injected = null;

    #[PostConstruct]
    public function init(InvokerGreeting $greeting): void
    {
        $this->calls[] = 'init';
        $this->injected = $greeting->text;
    }
}

/** NOT final: InvokerOverridingProxy below extends it to model a realistic AOP-proxy subclass. */
class InvokerMultiDestroyBean
{
    /** @var list<string> */
    public array $calls = [];

    #[PreDestroy]
    public function closeFirst(): void
    {
        $this->calls[] = 'closeFirst';
    }

    #[PreDestroy]
    public function closeSecond(): void
    {
        $this->calls[] = 'closeSecond';
    }
}

final class InvokerNoLifecycleBean {}

/**
 * A realistic AOP-proxy shape: a dynamic SUBCLASS overriding one method to wrap extra behaviour
 * around parent::, WITHOUT repeating its #[PreDestroy] attribute — attributes are not inherited
 * to an overriding method. (A pure composition/`__call`-forwarding wrapper was tried first and
 * rejected: Illuminate\Container\Container::call() does `new ReflectionMethod($object, $method)`
 * for dependency resolution, which throws when the object's class has no such method/no such
 * inherited method — so any real proxy MUST be inheritance-based to stay callable via
 * $container->call(), never a bare __call-forwarding wrapper.)
 */
final class InvokerOverridingProxy extends InvokerMultiDestroyBean
{
    /** @var list<string> */
    public array $proxyCalls = [];

    public function closeSecond(): void
    {
        $this->proxyCalls[] = 'proxy:closeSecond';
        parent::closeSecond();
    }
}

function makeInvoker(): InitDestroyInvoker
{
    return new InitDestroyInvoker(new Container);
}

it('invokes #[PostConstruct] via $container->call(), injecting the method\'s parameters', function () {
    $bean = new InvokerBean;

    makeInvoker()->invokeInit($bean, InvokerBean::class);

    expect($bean->calls)->toBe(['init'])
        ->and($bean->injected)->toBe('hello');
});

it('does nothing, without throwing, when the declared class has no #[PostConstruct] method', function () {
    $bean = new InvokerNoLifecycleBean;

    makeInvoker()->invokeInit($bean, InvokerNoLifecycleBean::class);

    expect(true)->toBeTrue();
});

it('invokes multiple #[PreDestroy] methods in REVERSE declaration order', function () {
    $bean = new InvokerMultiDestroyBean;

    makeInvoker()->invokeDestroy($bean, InvokerMultiDestroyBean::class);

    expect($bean->calls)->toBe(['closeSecond', 'closeFirst']);
});

it('hasDestroyMethods() reports whether the DECLARED class declares any #[PreDestroy] method', function () {
    $invoker = makeInvoker();

    expect($invoker->hasDestroyMethods(InvokerMultiDestroyBean::class))->toBeTrue()
        ->and($invoker->hasDestroyMethods(InvokerNoLifecycleBean::class))->toBeFalse();
});

it('invokeDestroy() discovers #[PreDestroy] methods from the DECLARED class even when $bean is a proxy subclass overriding one WITHOUT repeating the attribute (invariant 4)', function () {
    $proxy = new InvokerOverridingProxy;

    // Reflecting $proxy::class directly would silently MISS closeSecond(): its override on
    // InvokerOverridingProxy carries no #[PreDestroy] attribute of its own (attributes are not
    // inherited to an overriding method) — exactly the proxy-identity bug invariant 4 exists to
    // prevent. Passing the DECLARED class finds both methods; invocation still dispatches
    // polymorphically to the proxy's overridden implementation.
    makeInvoker()->invokeDestroy($proxy, InvokerMultiDestroyBean::class);

    expect($proxy->calls)->toBe(['closeSecond', 'closeFirst'])
        ->and($proxy->proxyCalls)->toBe(['proxy:closeSecond']);
});
