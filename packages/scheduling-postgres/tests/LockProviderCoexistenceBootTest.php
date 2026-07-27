<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Boot\FireflyKernel;
use Firefly\Scheduling\Lock\CacheLock;
use Firefly\Scheduling\Lock\DistributedLock;
use Firefly\Scheduling\Lock\NoneLock;
use Firefly\Scheduling\Postgres\PgAdvisoryLock;
use Firefly\Scheduling\Postgres\PgAdvisoryLockAutoConfiguration;
use Firefly\Scheduling\Postgres\SchedulingPostgresServiceProvider;
use Firefly\Scheduling\SchedulingAutoConfiguration;
use Firefly\Scheduling\SchedulingServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactoryContract;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Foundation\Application;

/**
 * BOTH-PROVIDERS COEXISTENCE, end-to-end over the REAL boot pipeline — the both-packages boot the M7
 * whole-branch reviewer performed by hand, now committed as a regression gate. firefly/scheduling
 * (SchedulingAutoConfiguration → the none/cache backend, gated #[ConditionalOnMissingBean]) and
 * firefly/scheduling-postgres (PgAdvisoryLockAutoConfiguration → the advisory-lock backend, gated
 * #[ConditionalOnProperty]) are BOTH installed at once. For provider=postgres BOTH auto-configs are
 * condition-eligible to supply DistributedLock; exactly one must win, deterministically, per
 * firefly.scheduling.lock.provider.
 *
 * This drives the COMMITTED manifests through the live Firefly\Context\Pass\ConditionPassTwoPass (NOT a
 * hand-wired container), so it guards the actual RESOLUTION, not just the #[Order] attribute value. It
 * exists because an evaluation-order shift that let scheduling win the #[ConditionalOnMissingBean] race
 * would silently downgrade a provider=postgres app to a NoneLock — no cross-node locking, and no error.
 * PgAdvisoryLockAutoConfiguration's explicit #[Order(900)] is what makes the postgres case below pass.
 */
function bootWithLockProvider(?string $provider): Application
{
    $scheduling = $provider === null ? [] : ['lock' => ['provider' => $provider]];

    // Both the Repository (SchedulingAutoConfiguration's distributedLock #[Bean], eagerly resolved when it
    // survives the condition pass) and the Factory (any Schedule resolution's CacheEventMutex/CacheSchedulingMutex)
    // are bound explicitly here — NOT via needs: ['cache']: Laravel's own
    // Application::registerCoreContainerAliases() pre-registers Illuminate\Contracts\Cache\Repository as an ALIAS
    // of 'cache.store', so $app->bound(Cache::class) is already true and the needs-menu's guarded default never
    // fires. Registration ORDER is deliberately scheduling-then-postgres: the winner must be fixed by the
    // condition pass's (#[Order], FQCN) sort, NEVER by which provider recorded its candidacy first.
    return fireflyApplication(
        ['firefly' => ['scheduling' => $scheduling]],
        [SchedulingServiceProvider::class, SchedulingPostgresServiceProvider::class],
        bindings: [
            CacheRepositoryContract::class => new CacheRepository(new ArrayStore),
            CacheFactoryContract::class => new class implements CacheFactoryContract
            {
                public function store($name = null)
                {
                    return new CacheRepository(new ArrayStore);
                }
            },
        ],
    );
}

/**
 * @return list<string> "FQCN::method" of every surviving auto-config #[Bean] method that returns
 *                      DistributedLock — after the condition pass has removed the loser's bean method.
 *                      Exactly one entry proves the race resolved to a single backend (the other backed off).
 */
function survivingDistributedLockBeans(Application $app): array
{
    /** @var FireflyKernel $kernel */
    $kernel = $app->make(FireflyKernel::class);

    $beans = [];
    foreach ($kernel->context()->definitions->all() as $definition) {
        foreach ($definition->descriptor->beans as $bean) {
            if ($bean->returns === DistributedLock::class) {
                $beans[] = $definition->class().'::'.$bean->method;
            }
        }
    }
    sort($beans);

    return $beans;
}

it('binds PgAdvisoryLock and makes scheduling back off when provider = postgres', function () {
    $app = bootWithLockProvider('postgres');

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    // Postgres wins the #[ConditionalOnMissingBean] race explicitly (lower #[Order]); scheduling backs
    // off, so EXACTLY ONE DistributedLock bean survives and it is the advisory-lock one.
    expect($context->get(DistributedLock::class))->toBeInstanceOf(PgAdvisoryLock::class)
        ->and(survivingDistributedLockBeans($app))->toBe([PgAdvisoryLockAutoConfiguration::class.'::distributedLock']);
});

it('binds CacheLock from scheduling when provider = cache (postgres stays inert)', function () {
    $app = bootWithLockProvider('cache');

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    // Postgres's #[ConditionalOnProperty] does NOT fire, so scheduling provides — again exactly one bean.
    expect($context->get(DistributedLock::class))->toBeInstanceOf(CacheLock::class)
        ->and(survivingDistributedLockBeans($app))->toBe([SchedulingAutoConfiguration::class.'::distributedLock']);
});

it('binds NoneLock from scheduling when no provider is configured', function () {
    $app = bootWithLockProvider(null);

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    expect($context->get(DistributedLock::class))->toBeInstanceOf(NoneLock::class)
        ->and(survivingDistributedLockBeans($app))->toBe([SchedulingAutoConfiguration::class.'::distributedLock']);
});
