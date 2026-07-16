<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\AutoConfigure\Tests\E2EFixtures\Cache\DefaultCacheAutoConfig;
use Firefly\AutoConfigure\Tests\E2EFixtures\CacheUser\RedisCacheAdapter;
use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\BPort;
use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\CachePort;
use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\DefaultB;
use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\DefaultX;
use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\InMemoryCache;
use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\LowCache;
use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\XPort;
use Firefly\AutoConfigure\Tests\E2EFixtures\ValidatorUser\AppValidator;
use Firefly\AutoConfigure\Tests\Support\ManifestPathAutoConfiguration;
use Firefly\AutoConfigure\Tests\Support\SecondManifestPathAutoConfiguration;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Validation\IlluminateValidator;
use Firefly\Validation\Rule\Iban;
use Firefly\Validation\Validator;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

/**
 * PSR-4 map for one E2E fixture subdirectory.
 *
 * @return array<string, string>
 */
function e2eFixtures(string $sub): array
{
    return ['Firefly\\AutoConfigure\\Tests\\E2EFixtures\\'.$sub.'\\' => __DIR__.'/E2EFixtures/'.$sub];
}

function e2eTempFile(): string
{
    return sys_get_temp_dir().'/firefly-e2e-'.bin2hex(random_bytes(8)).'.php';
}

/**
 * Compiles each candidate PSR-4 map to a temp manifest pair, boots a REAL Application through the REAL
 * bootstrap provider + real candidate providers, hands the resulting ApplicationContext to $assert, and always
 * cleans up. $bootstrapFirst flips whether the bootstrap or the candidates register first (proving the result
 * is independent of provider-registration order — the F2 inversion). $configure runs after 'config' is bound.
 *
 * @param  array<string,mixed>  $firefly  the `firefly.*` config subtree
 * @param  list<array<string,string>>  $candidatePsr4List  one PSR-4 map per auto-config candidate
 */
function bootScenario(
    array $firefly,
    array $candidatePsr4List,
    Closure $assert,
    bool $bootstrapFirst = false,
    ?Closure $configure = null,
): void {
    $tempPaths = [];

    try {
        $candidatePathPairs = [];
        foreach ($candidatePsr4List as $psr4) {
            $componentPath = e2eTempFile();
            $contextPath = e2eTempFile();
            (new AutoConfigManifestCompiler)->write($psr4, $componentPath, $contextPath);
            $tempPaths[] = $componentPath;
            $tempPaths[] = $contextPath;
            $candidatePathPairs[] = [$componentPath, $contextPath];
        }

        $app = new Application;
        $app->instance('config', new Repository(['firefly' => $firefly]));

        if ($configure !== null) {
            $configure($app);
        }

        // Each candidate MUST register under a DISTINCT provider class: both Laravel's
        // Application::register() and AutoConfigurationCollector::add() dedupe by provider FQCN, so
        // two same-class instances would silently collapse to one candidate. In production every
        // capability package ships its own AutoConfiguration subclass, so real candidates are always
        // distinct classes; the harness mirrors that with one distinct provider per candidate slot.
        $registerCandidates = function () use ($app, $candidatePathPairs): void {
            foreach ($candidatePathPairs as $index => [$componentPath, $contextPath]) {
                $provider = match ($index) {
                    0 => new ManifestPathAutoConfiguration($app, $componentPath, $contextPath),
                    1 => new SecondManifestPathAutoConfiguration($app, $componentPath, $contextPath),
                    default => throw new LogicException('The e2e harness ships two distinct candidate provider classes; add another to register more than two candidates.'),
                };
                $app->register($provider);
            }
        };

        if ($bootstrapFirst) {
            $app->register(new FireflyAutoConfigureServiceProvider($app));
            $registerCandidates();
        } else {
            $registerCandidates();
            $app->register(new FireflyAutoConfigureServiceProvider($app));
        }

        $app->boot();

        /** @var ApplicationContext $context */
        $context = $app->make(ApplicationContext::class);
        $assert($context, $app);
    } finally {
        foreach ($tempPaths as $path) {
            @unlink($path);
        }
    }
}

// --- Scenario 1: default backs off when the user supplies the bean ---

it('1a: a starter default backs off once the user supplies the bean', function () {
    bootScenario(
        firefly: ['scan' => ['paths' => e2eFixtures('CacheUser')]],
        candidatePsr4List: [e2eFixtures('Cache')],
        assert: function (ApplicationContext $context, Application $app) {
            // The load-bearing property: the user's bean wins, so the default's product (InMemoryCache)
            // backed off — CachePort resolves to RedisCacheAdapter, NOT InMemoryCache.
            expect($context->get(CachePort::class))->toBeInstanceOf(RedisCacheAdapter::class)
                ->and($context->get(CachePort::class))->not->toBeInstanceOf(InMemoryCache::class)
                // The #[Configuration] class ITSELF stays a container binding even though its only
                // #[Bean] method backed off: ContainerRegistrar::bindClass() binds every surviving
                // component, and a class-condition-free #[Configuration] survives ConditionPassTwo with
                // an empty bean list (Spring-consistent — a @Configuration is itself a bean, and its
                // class binding is what a @Bean factory resolves to call the method). What backs off is
                // the BEAN, not the declaring configuration. (The brief's original ->toBeFalse() encoded
                // the opposite, incorrect expectation; see the task report.)
                ->and($app->bound(DefaultCacheAutoConfig::class))->toBeTrue();
        },
    );
});

it('1b: CONTROL — the same default IS present when no user bean exists (proves the condition gates and never self-sees)', function () {
    bootScenario(
        firefly: [],
        candidatePsr4List: [e2eFixtures('Cache')],
        assert: fn (ApplicationContext $context) => expect($context->get(CachePort::class))->toBeInstanceOf(InMemoryCache::class),
    );
});

// --- Scenario 2: two starters -> first (lowest #[Order]) wins, in BOTH registration orders ---

it('2: two starters supplying one port -> the lower #[Order] wins, the other backs off, independent of registration order', function () {
    foreach ([false, true] as $bootstrapFirst) {
        bootScenario(
            firefly: [],
            candidatePsr4List: [e2eFixtures('CacheLow'), e2eFixtures('CacheHigh')],
            assert: fn (ApplicationContext $context) => expect($context->get(CachePort::class))->toBeInstanceOf(LowCache::class),
            bootstrapFirst: $bootstrapFirst,
        );
    }
});

// --- Scenario 3: #[ConditionalOnProperty]-gated auto-config ---

it('3: a #[ConditionalOnProperty]-gated auto-config is present when true and absent when missing', function () {
    bootScenario(
        firefly: ['feature' => ['x' => ['enabled' => 'true']]],
        candidatePsr4List: [e2eFixtures('PropX')],
        assert: fn (ApplicationContext $context) => expect($context->get(XPort::class))->toBeInstanceOf(DefaultX::class),
    );

    bootScenario(
        firefly: [],
        candidatePsr4List: [e2eFixtures('PropX')],
        assert: fn (ApplicationContext $context, Application $app) => expect($app->bound(XPort::class))->toBeFalse(),
    );
});

// --- Scenario 4: ordering across auto-configs with a dependency ---

it('4: an auto-config #[ConditionalOnBean] sees an earlier-accepted auto-config, and backs off when it is absent', function () {
    bootScenario(
        firefly: ['a' => ['enabled' => 'true']],
        candidatePsr4List: [e2eFixtures('DepA'), e2eFixtures('DepB')],
        assert: fn (ApplicationContext $context) => expect($context->get(BPort::class))->toBeInstanceOf(DefaultB::class),
    );

    bootScenario(
        firefly: [],
        candidatePsr4List: [e2eFixtures('DepA'), e2eFixtures('DepB')],
        assert: fn (ApplicationContext $context, Application $app) => expect($app->bound(BPort::class))->toBeFalse(),
    );
});

// --- Scenario 5: user bean-condition is illegal end-to-end ---

it('5: a user component carrying a bean condition aborts boot with the teaching ConfigurationException', function () {
    try {
        bootScenario(
            firefly: ['scan' => ['paths' => e2eFixtures('IllegalUser')]],
            candidatePsr4List: [],
            assert: fn () => null,
        );
        throw new RuntimeException('expected a ConfigurationException');
    } catch (ConfigurationException $e) {
        expect($e->getMessage())->toContain('not supported on user component');
    }
});

// --- Scenario 6: validation auto-config is the live end-to-end proof ---

it('6: ValidationAutoConfiguration wires the default Validator end-to-end and backs off to a user Validator', function () {
    $validationSrc = ['Firefly\\Validation\\' => dirname(__DIR__, 2).'/validation/src'];
    $bindFactory = fn (Application $app) => $app->instance(Factory::class, new IlluminateFactory(new Translator(new ArrayLoader, 'en')));

    bootScenario(
        firefly: [],
        candidatePsr4List: [$validationSrc],
        assert: function (ApplicationContext $context) {
            /** @var Validator $validator */
            $validator = $context->get(Validator::class);
            expect($validator)->toBeInstanceOf(IlluminateValidator::class);

            try {
                $validator->validate(['iban' => 'XX'], ['iban' => [new Iban]]);
                throw new RuntimeException('expected a ValidationException');
            } catch (ValidationException $e) {
                expect($e->fieldErrors())->not->toBeEmpty()
                    ->and($e->fieldErrors()[0]->field)->toBe('iban');
            }
        },
        configure: $bindFactory,
    );

    bootScenario(
        firefly: ['scan' => ['paths' => e2eFixtures('ValidatorUser')]],
        candidatePsr4List: [$validationSrc],
        assert: fn (ApplicationContext $context) => expect($context->get(Validator::class))->toBeInstanceOf(AppValidator::class),
        configure: $bindFactory,
    );
});
