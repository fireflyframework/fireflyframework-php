<?php

declare(strict_types=1);

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Info\InfoContributor;
use Firefly\Actuator\Info\InfoContributorRegistry;
use Firefly\Actuator\Info\InfoEndpoint;
use Firefly\Actuator\Info\RuntimeInfoContributor;
use Firefly\Kernel\Version;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Foundation\Application;

/**
 * Boots the same bare skeleton PackageBootTest does (empty RouteManifest/ScheduledManifest stubbed via the
 * harness's bindings menu rather than dragging in Web's and Scheduling's whole boot pipelines) so the
 * REGISTRATION half of the contract is exercised end to end: nothing registers RuntimeInfoContributor
 * explicitly — it has to be discovered as a #[Component] InfoContributor by InfoContributorRegistrar, and its
 * #[ConditionalOnProperty] has to be evaluated by the real condition pipeline off the compiled context
 * manifest. A unit test over the class alone would prove neither.
 *
 * @param  array<string, mixed>  $management
 */
function runtimeInfoApp(array $management = []): Application
{
    return fireflyApplication(
        config: ['firefly' => ['management' => ['enabled' => true] + $management]],
        providers: [ActuatorServiceProvider::class, ActuatorWiringProvider::class],
        bindings: [
            RouteManifest::class => new RouteManifest([]),
            ScheduledManifest::class => new ScheduledManifest([]),
        ],
    );
}

/** @return list<class-string<InfoContributor>> */
function runtimeInfoContributors(Application $app): array
{
    /** @var InfoContributorRegistry $registry */
    $registry = $app->make(InfoContributorRegistry::class);

    return array_map(static fn (InfoContributor $c): string => $c::class, $registry->all());
}

/**
 * One section of the `runtime` fragment, narrowed to a real string-keyed array by control flow rather than by
 * an inline-PHPDoc override — so a shape regression (a section that stops being an array, or disappears)
 * fails HERE with a readable message instead of surfacing as a mixed-offset access.
 *
 * @param  array<string, mixed>  $info
 * @return array<string, mixed>
 */
function runtimeSection(array $info, string ...$path): array
{
    $node = $info;
    foreach ($path as $key) {
        $value = $node[$key] ?? null;
        if (! is_array($value)) {
            throw new RuntimeException('Expected ['.implode('.', $path)."] to be an array; [{$key}] is not.");
        }

        $narrowed = [];
        foreach ($value as $k => $v) {
            $narrowed[(string) $k] = $v;
        }
        $node = $narrowed;
    }

    return $node;
}

/**
 * @param  array<string, mixed>  $section
 */
function runtimeInt(array $section, string $key): int
{
    $value = $section[$key] ?? null;
    if (! is_int($value)) {
        throw new RuntimeException("Expected [{$key}] to be an int, got ".get_debug_type($value).'.');
    }

    return $value;
}

it('publishes the runtime facts under the runtime key', function () {
    $info = (new RuntimeInfoContributor)->info();

    $runtime = runtimeSection($info, 'runtime');
    $php = runtimeSection($runtime, 'php');
    $memory = runtimeSection($runtime, 'memory');

    // Asserted structurally, not by value: PHP_VERSION and the memory figures differ per machine and per run,
    // so pinning literals here would make the suite fail on somebody else's laptop for no reason. What IS
    // pinned is the exact key set and the type of every leaf — which is what the dashboard renders against.
    expect(array_keys($runtime))->toBe(['php', 'laravel', 'firefly', 'memory'])
        ->and(array_keys($php))->toBe(['version', 'sapi', 'opcache'])
        ->and($php['version'])->toBe(PHP_VERSION)
        ->and($php['sapi'])->toBe(PHP_SAPI)
        ->and($php['opcache'])->toBeBool()
        ->and(runtimeSection($runtime, 'laravel')['version'])->toBe(Application::VERSION)
        ->and(runtimeSection($runtime, 'firefly')['version'])->toBe(Version::VERSION)
        ->and(array_keys($memory))->toBe(['used', 'peak'])
        ->and(runtimeInt($memory, 'peak'))->toBeGreaterThanOrEqual(runtimeInt($memory, 'used'));
});

// The whole point of the contributor: /actuator/info answered `{}` on a fresh application, and the dashboard
// could only render an apology. This is the regression that must never come back.
it('makes /info non-empty with no application configuration at all', function () {
    $registry = new InfoContributorRegistry;
    $registry->register(new RuntimeInfoContributor);

    $body = (new InfoEndpoint($registry))->handle(new EndpointRequest('GET', []))->body;

    expect($body)->not->toBe([])
        ->and($body)->toHaveKey('runtime');
});

it('is discovered and registered on a real boot with no configuration', function () {
    expect(runtimeInfoContributors(runtimeInfoApp()))->toContain(RuntimeInfoContributor::class);
});

// Off means GONE, not "registered but returning []": the condition is evaluated at boot, and
// InfoContributorRegistrar only ever sees definitions that survived condition filtering.
it('is not registered at all when firefly.management.info.runtime.enabled is false', function () {
    $contributors = runtimeInfoContributors(runtimeInfoApp(['info' => ['runtime' => ['enabled' => false]]]));

    expect($contributors)->not->toContain(RuntimeInfoContributor::class);
});

it('is registered when the switch is explicitly true', function () {
    $contributors = runtimeInfoContributors(runtimeInfoApp(['info' => ['runtime' => ['enabled' => true]]]));

    expect($contributors)->toContain(RuntimeInfoContributor::class);
});
