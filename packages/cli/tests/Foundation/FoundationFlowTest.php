<?php

declare(strict_types=1);

use Firefly\Cli\Cache\FireflyCachePaths;
use Firefly\Cli\Tests\Fixtures\Foundation\CountWidgets;
use Firefly\Cli\Tests\Fixtures\Foundation\FoundationController;
use Firefly\Cli\Tests\Fixtures\Foundation\FoundationProperties;
use Firefly\Cli\Tests\Fixtures\Foundation\FoundationService;
use Firefly\Cli\Tests\Fixtures\Foundation\FoundationWriter;
use Firefly\Cli\Tests\Fixtures\Foundation\RegisterWidget;
use Firefly\Cli\Tests\Fixtures\Foundation\WidgetRecord;
use Firefly\Cli\Tests\Fixtures\Foundation\WidgetRegistered;
use Firefly\Cli\Tests\Fixtures\Foundation\WidgetRepository;
use Firefly\Cli\Tests\Support\FoundationFlowTestCase;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Eda\EventPublisher;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Testing\Fixture\ListenerSpy;
use Firefly\Web\Security\ControllerSecurityGuard;

/**
 * FOUNDATION FINALE (M14/T10): the WHOLE stack — DI, config, web, validation, data, transactions, CQRS, events,
 * security, actuator — proven over the CACHED zero-reflection path in ONE app. The manifests are compiled by the
 * real ManifestCacheWriter (what `artisan firefly:cache` emits) in FoundationFlowTestCase::setUp(); every assertion
 * below runs on the CACHED-path boot (FireflyCacheServiceProvider bound the manifests; NO scan.paths configured).
 */
uses(FoundationFlowTestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

it('compiles the fixture app with real proxy generation (firefly:cache emission)', function () {
    // Step 1: the compile ran in setUp() over the fixture PSR-4 root — assert the classmap artifact + proxies.
    expect(is_file(FoundationFlowTestCase::$cacheDir.'/'.FireflyCachePaths::COMPONENT))->toBeTrue()
        ->and(FoundationFlowTestCase::$report?->proxyCount)->toBeGreaterThan(0);
});

it('resolves a #[Service] from the container (DI)', function () {
    /** @var FoundationFlowTestCase $this */
    /** @var FoundationService $service */
    $service = $this->fireflyContext()->get(FoundationService::class);
    expect($service->greet())->toBe('hello-foundation');
});

it('binds a #[ConfigProperties] DTO FROM config on the CACHED path', function () {
    /** @var FoundationFlowTestCase $this */
    // Bound by FireflyCacheServiceProvider via ConfigRegistrar from the emitted config-properties.php; the value is
    // the SEEDED config ('from-config-value'), NOT the constructor default — proving population FROM config, not a
    // bare autowired resolution. Asserted ONLY on the cached path (the dev boot leaves it unbound by design).
    /** @var FoundationProperties $props */
    $props = $this->fireflyContext()->get(FoundationProperties::class);
    expect($props->greeting)->toBe('from-config-value');
});

it('serves GET / as 200 through the real HTTP pipeline', function () {
    /** @var FoundationFlowTestCase $this */
    $this->getJson('/')->assertStatus(200)->assertExactJson(['status' => 'ok']);
});

it('renders an invalid #[Valid] body as 422', function () {
    /** @var FoundationFlowTestCase $this */
    // Empty `name` fails the compiled NotBlank constraint (loaded from constraints.php on the cached path).
    $this->postJson('/widgets', ['name' => ''])->assertStatus(422);
});

it('returns the seeded row via an EloquentRepository derived query', function () {
    /** @var FoundationFlowTestCase $this */
    WidgetRecord::query()->create(['status' => 'open', 'amount' => 10]);
    WidgetRecord::query()->create(['status' => 'closed', 'amount' => 20]);

    /** @var WidgetRepository $repo */
    $repo = $this->fireflyContext()->get(WidgetRepository::class);
    $rows = $repo->findByStatus('open');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->status)->toBe('open');
});

it('commits a #[Transactional] write via the generated proxy subclass', function () {
    /** @var FoundationFlowTestCase $this */
    /** @var FoundationWriter $writer */
    $writer = $this->fireflyContext()->get(FoundationWriter::class);

    // The resolved bean IS the generated proxy subclass, and the write COMMITTED (the row persists after the
    // framework-managed transaction) — both facets of "#[Transactional] works on the cached path".
    expect($writer::class)->toBe(FoundationWriter::class.'__FireflyTransactionalProxy')
        ->and($writer->store('committed'))->toBeGreaterThan(0)
        ->and(WidgetRecord::query()->where('status', 'committed')->exists())->toBeTrue();
});

it('dispatches a CQRS command and reads it back through a query', function () {
    /** @var FoundationFlowTestCase $this */
    $context = $this->fireflyContext();
    /** @var CommandBus $commandBus */
    $commandBus = $context->get(CommandBus::class);
    /** @var QueryBus $queryBus */
    $queryBus = $context->get(QueryBus::class);

    $commandBus->send(new RegisterWidget('gadget'));

    expect($queryBus->ask(new CountWidgets))->toBe(1);
});

it('delivers a domain event to its #[EventListener]', function () {
    /** @var FoundationFlowTestCase $this */
    $app = $this->app();
    $event = new WidgetRegistered('gadget');

    // Publish the DomainEvent's eventType onto the eda broker bus; WidgetEventListener records it via the ListenerSpy
    // (subscribed from the CACHED event-listeners.php by EventListenerWiringPass).
    $app->make(EventPublisher::class)->publish('firefly.events', $event->eventType(), ['name' => $event->name]);

    expect($app->make(ListenerSpy::class)->seen)->toBe(['WidgetRegistered']);
});

it('enforces #[PreAuthorize]: denies without the role and allows with it', function () {
    /** @var FoundationFlowTestCase $this */
    // ControllerSecurityGuard was overridden to the real MethodSecurityControllerGuard by SecurityWiringPass, backed
    // by the CACHED SecurityMethodManifest. An empty manifest would make ruleFor() null → always-allow, so the deny
    // assertion inherently proves the #[PreAuthorize] rule survived the compile.
    /** @var ControllerSecurityGuard $guard */
    $guard = $this->fireflyContext()->get(ControllerSecurityGuard::class);

    // Deny: anonymous → the hasRole('ADMIN') rule fails → 401 (AuthenticationException).
    SecurityContextHolder::clearContext();
    expect(fn () => $guard->check(FoundationController::class, 'admin', []))
        ->toThrow(AuthenticationException::class);

    // Allow: with ROLE_ADMIN the rule passes and check() returns void (no throw).
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('root', 'root', [new SimpleGrantedAuthority('ROLE_ADMIN')]),
    ));
    $allowed = false;
    $guard->check(FoundationController::class, 'admin', []);
    $allowed = true;

    expect($allowed)->toBeTrue();
});

it('serves GET /actuator/health as UP', function () {
    /** @var FoundationFlowTestCase $this */
    $this->getJson('/actuator/health')->assertStatus(200)->assertJsonPath('status', 'UP');
});
