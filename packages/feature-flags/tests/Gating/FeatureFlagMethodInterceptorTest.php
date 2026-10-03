<?php

declare(strict_types=1);

use Firefly\Data\Proxy\MethodInterceptor;
use Firefly\Data\Proxy\MethodInvocation;
use Firefly\FeatureFlags\Gating\FeatureFlagDisabledException;
use Firefly\FeatureFlags\Gating\FeatureFlagMethodDescriptor;
use Firefly\FeatureFlags\Gating\FeatureFlagMethodInterceptor;
use Firefly\FeatureFlags\Gating\RouteGateDecisions;
use Firefly\FeatureFlags\Tests\Support\GateFixtures;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

class FeatureFlagsPricing
{
    public int $calls = 0;

    public function price(int $cents): string
    {
        $this->calls++;

        return "new:{$cents}";
    }

    public function legacy(int $cents): string
    {
        return "legacy:{$cents}";
    }
}

final class FeatureFlagsCustomPricing extends FeatureFlagsPricing
{
    public function legacy(int $cents): string
    {
        return "custom:{$cents}";
    }
}

function featureFlagsInvoke(FeatureFlagsPricing $target, FeatureFlagMethodDescriptor $rule, RouteGateDecisions $decisions): mixed
{
    $interceptor = new FeatureFlagMethodInterceptor(GateFixtures::gate(), $decisions);
    $invocation = new MethodInvocation($target, FeatureFlagsPricing::class, 'price', [100], [$interceptor], [FeatureFlagMethodDescriptor::class => $rule], static function (array $arguments) use ($target): string {
        /** @var int $cents */
        $cents = $arguments[0];

        return $target->price($cents);
    });

    return $invocation->proceed();
}

it('proceeds while on and refuses an off flag without calling the target', function (): void {
    $target = new FeatureFlagsPricing;
    $decisions = new RouteGateDecisions(new Container);

    expect(featureFlagsInvoke($target, new FeatureFlagMethodDescriptor(FeatureFlagsPricing::class, 'price', 'on'), $decisions))->toBe('new:100')
        ->and(fn () => featureFlagsInvoke($target, new FeatureFlagMethodDescriptor(FeatureFlagsPricing::class, 'price', 'off'), $decisions))->toThrow(FeatureFlagDisabledException::class)
        ->and($target->calls)->toBe(1);
});

it('calls a named fallback with the arguments on the actual receiver', function (): void {
    $target = new FeatureFlagsCustomPricing;

    expect(featureFlagsInvoke($target, new FeatureFlagMethodDescriptor(FeatureFlagsPricing::class, 'price', 'off', fallback: 'legacy'), new RouteGateDecisions(new Container)))
        ->toBe('custom:100')
        ->and($target->calls)->toBe(0);
});

it('does not re-enter the gate when a class-level fallback is proxied and still runs other advice', function (): void {
    $gate = GateFixtures::gate();
    $decisions = new RouteGateDecisions(new Container);
    $interceptor = new FeatureFlagMethodInterceptor($gate, $decisions);
    $other = new class implements MethodInterceptor
    {
        public int $calls = 0;

        public function invoke(MethodInvocation $invocation): mixed
        {
            $this->calls++;

            return $invocation->proceed();
        }
    };
    $target = new class($interceptor, $other) extends FeatureFlagsPricing
    {
        public function __construct(
            private readonly FeatureFlagMethodInterceptor $interceptor,
            private readonly MethodInterceptor $other,
        ) {}

        public function legacy(int $cents): string
        {
            $invocation = new MethodInvocation($this, FeatureFlagsPricing::class, 'legacy', [$cents], [$this->interceptor, $this->other], [FeatureFlagMethodDescriptor::class => new FeatureFlagMethodDescriptor(FeatureFlagsPricing::class, 'legacy', 'off', fallback: 'legacy')], static function (array $arguments): string {
                $value = $arguments[0] ?? null;
                if (! is_int($value)) {
                    throw new LogicException('Expected a cent amount');
                }

                return 'fallback:'.$value;
            });

            $result = $invocation->proceed();
            if (! is_string($result)) {
                throw new LogicException('Expected a price');
            }

            return $result;
        }
    };
    $invocation = new MethodInvocation($target, FeatureFlagsPricing::class, 'price', [100], [$interceptor], [FeatureFlagMethodDescriptor::class => new FeatureFlagMethodDescriptor(FeatureFlagsPricing::class, 'price', 'off', fallback: 'legacy')], static function (array $arguments): string {
        $value = $arguments[0] ?? null;
        if (! is_int($value)) {
            throw new LogicException('Expected a cent amount');
        }

        return 'new:'.$value;
    });

    expect($invocation->proceed())->toBe('fallback:100')
        ->and($other->calls)->toBe(1);
});

it('trusts the route decision only on matching route rows of the current request', function (): void {
    $app = new Container;
    $request = Request::create('/beta');
    $route = new Route('GET', '/beta', static fn (): string => 'beta');
    $request->setRouteResolver(static fn (): Route => $route);
    $app->instance('request', $request);
    $decisions = new RouteGateDecisions($app);
    $rule = new FeatureFlagMethodDescriptor(FeatureFlagsPricing::class, 'price', 'off', route: true);
    $decisions->register($route, $rule);
    $decisions->record($request, 'off', null, false);

    expect(featureFlagsInvoke(new FeatureFlagsPricing, $rule, $decisions))->toBe('new:100')
        ->and(fn () => featureFlagsInvoke(new FeatureFlagsPricing, new FeatureFlagMethodDescriptor(FeatureFlagsPricing::class, 'price', 'off'), $decisions))->toThrow(FeatureFlagDisabledException::class)
        ->and($decisions->passed(new FeatureFlagMethodDescriptor(FeatureFlagsPricing::class, 'price', 'off', 'v2', route: true)))->toBeFalse()
        ->and($decisions->passed(new FeatureFlagMethodDescriptor(FeatureFlagsPricing::class, 'price', 'off', default: true, route: true)))->toBeFalse()
        ->and($decisions->passed(new FeatureFlagMethodDescriptor(FeatureFlagsPricing::class, 'other', 'off', route: true)))->toBeFalse()
        ->and((new RouteGateDecisions(new Container))->passed($rule))->toBeFalse();

    $app->instance('request', Request::create('/next'));
    expect($decisions->passed($rule))->toBeFalse();
});

it('round trips descriptor rows and spells route middleware', function (): void {
    $rule = new FeatureFlagMethodDescriptor('App\\Http\\BetaController', 'index', 'beta-api', 'v2', true, null, true);

    expect(FeatureFlagMethodDescriptor::fromArray($rule->toArray()))->toEqual($rule)
        ->and(FeatureFlagMethodDescriptor::fromArray(['class' => 'A', 'method' => 'b', 'key' => 'k']))->toEqual(new FeatureFlagMethodDescriptor('A', 'b', 'k'))
        ->and($rule->middleware())->toBe('feature-flag:beta-api,v2,true')
        ->and((new FeatureFlagMethodDescriptor('A', 'b', 'k'))->middleware())->toBe('feature-flag:k,,false')
        ->and($rule->site())->toBe('App\\Http\\BetaController::index');
});
