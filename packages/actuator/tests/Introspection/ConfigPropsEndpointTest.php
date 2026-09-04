<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Actuator\Introspection\ConfigPropsEndpoint;
use Firefly\Actuator\Tests\Fixtures\DemoProperties;
use Firefly\Actuator\Tests\Fixtures\ProdOnlyProperties;
use Firefly\Actuator\Tests\Fixtures\UnbindableProperties;
use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Config\Registrar\ConfigRegistrar;
use Firefly\Config\Scanner\ConfigPropertiesDescriptor;
use Firefly\Config\Scanner\ConfigPropertiesManifest;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * The DTOs are registered through the REAL ConfigRegistrar over a real manifest — not hand-bound with
 * $container->instance() — because the whole value of /configprops is that it reports what the framework's own
 * binding actually produced. A test that hand-built the instances would still pass if relaxed binding, scalar
 * coercion or profile gating broke.
 *
 * @param  list<ConfigPropertiesDescriptor>  $descriptors
 * @param  array<string, mixed>  $config
 * @param  list<string>  $profiles
 */
function configPropsContainer(array $descriptors, array $config = [], array $profiles = ['test']): Container
{
    $repository = new Repository($config);
    $manifest = new ConfigPropertiesManifest($descriptors);

    $container = new Container;
    $container->instance('config', $repository);
    (new ConfigRegistrar($container, new Config($repository), profiles: new Profiles($profiles)))->register($manifest);

    // Bound so the endpoint uses THIS manifest rather than falling back to its AppScan discovery — the
    // container-bound manifest winning is itself part of the contract (see ConfigPropsEndpoint::discoverManifest).
    $container->instance(ConfigPropertiesManifest::class, $manifest);

    return $container;
}

/**
 * EndpointResponse::$body is `array<mixed>|string` — narrowed via real control flow, never a suppressing
 * inline-PHPDoc or assert() override. Named distinctly from the sibling test files' helpers so a whole-package
 * Pest run, which loads every file into one process, has no top-level function collision.
 *
 * @return array<mixed>
 */
function configPropsJsonBody(EndpointResponse $response): array
{
    if (! is_array($response->body)) {
        throw new RuntimeException('Expected a JSON (array) response body.');
    }

    return $response->body;
}

/**
 * The `beans` map, narrowed to string keys and array rows by real checks so the assertions below index a type
 * PHPStan can see rather than a bare mixed.
 *
 * @return array<string, array<mixed>>
 */
function configPropsBeans(EndpointResponse $response): array
{
    $value = configPropsJsonBody($response)['beans'] ?? null;
    if (! is_array($value)) {
        throw new RuntimeException('Expected [beans] to be an array.');
    }

    $rows = [];
    foreach ($value as $class => $row) {
        if (! is_array($row)) {
            throw new RuntimeException('Expected each [beans] entry to be an array.');
        }
        $rows[(string) $class] = $row;
    }

    return $rows;
}

it('lists each bound DTO with its prefix and the values it actually resolved', function () {
    $container = configPropsContainer(
        [new ConfigPropertiesDescriptor(DemoProperties::class, 'demo')],
        ['demo' => [
            'name' => 'checkout',
            // A STRING where the DTO declares int: proves the row shows the COERCED value the application
            // will use (250), not the raw config scalar ('250').
            'retries' => '250',
            'api_token' => 'super-secret-token',            // relaxed binding: snake_case -> $apiToken
            'signing_keys' => ['active' => 'PRIVATE-A', 'previous' => 'PRIVATE-B'],
            'endpoint' => ['url' => 'https://demo.test', 'password' => 'hunter2'],
            // No 'mode' key on purpose: ReflectionConfigBinder does not coerce a string into a backed enum, so
            // the DTO holds its constructor default and the row proves the renderer publishes the enum's
            // BACKING VALUE ('strict') rather than 'DemoMode::Strict' or an unencodable object.
        ]],
    );

    $beans = configPropsBeans((new ConfigPropsEndpoint($container))->handle(new EndpointRequest('GET', [])));

    expect($beans)->toHaveCount(1)
        ->and($beans[DemoProperties::class])->toBe([
            'class' => DemoProperties::class,
            'prefix' => 'demo',
            'profiles' => [],
            'bound' => true,
            'properties' => [
                'name' => 'checkout',
                'retries' => 250,
                'apiToken' => '******',
                'signingKeys' => '******',
                'endpoint' => ['url' => 'https://demo.test', 'password' => '******'],
                'mode' => 'strict',
            ],
            'error' => null,
        ]);
});

// The audit finding, pinned: a sensitive key holding an ARRAY must be replaced wholesale, never descended
// into. $signingKeys is the shape that used to leak — `keys` matched the regex but was an array, so each leaf
// was judged on its own harmless name ('active', 'previous') and both private keys rendered in full.
it('masks a sensitive property that holds an array, leaking neither values nor shape', function () {
    $container = configPropsContainer(
        [new ConfigPropertiesDescriptor(DemoProperties::class, 'demo')],
        ['demo' => [
            'name' => 'checkout',
            'retries' => 1,
            'api_token' => 't',
            'signing_keys' => ['active' => 'PRIVATE-A', 'previous' => 'PRIVATE-B'],
            'endpoint' => ['url' => 'https://demo.test', 'password' => 'hunter2'],
        ]],
    );

    // JSON_UNESCAPED_SLASHES so the URL assertion reads the way the dispatch action actually renders it.
    $flat = json_encode(
        configPropsJsonBody((new ConfigPropsEndpoint($container))->handle(new EndpointRequest('GET', []))),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );

    expect($flat)->not->toContain('PRIVATE-A')
        ->and($flat)->not->toContain('PRIVATE-B')
        ->and($flat)->not->toContain('active')      // the shape of the keyring is withheld too
        ->and($flat)->not->toContain('hunter2')
        ->and($flat)->toContain('https://demo.test');
});

it('reports a profile-gated DTO as declared-but-unbound instead of dropping it', function () {
    $container = configPropsContainer(
        [new ConfigPropertiesDescriptor(ProdOnlyProperties::class, 'prodonly', ['prod'])],
        ['prodonly' => ['endpoint' => 'https://prod.test']],
        ['test'],
    );

    $beans = configPropsBeans((new ConfigPropsEndpoint($container))->handle(new EndpointRequest('GET', [])));

    expect($beans)->toBe([ProdOnlyProperties::class => [
        'class' => ProdOnlyProperties::class,
        'prefix' => 'prodonly',
        'profiles' => ['prod'],
        'bound' => false,
        'properties' => [],
        'error' => null,
    ]]);
});

it('reports a DTO that cannot bind on its own row and still renders the others', function () {
    $container = configPropsContainer(
        [
            new ConfigPropertiesDescriptor(UnbindableProperties::class, 'unbindable'),
            new ConfigPropertiesDescriptor(ProdOnlyProperties::class, 'prodonly'),
        ],
        ['prodonly' => ['endpoint' => 'https://prod.test']],
    );

    $beans = configPropsBeans((new ConfigPropsEndpoint($container))->handle(new EndpointRequest('GET', [])));

    $broken = $beans[UnbindableProperties::class];

    expect($beans)->toHaveCount(2)
        ->and($beans[ProdOnlyProperties::class]['bound'])->toBeTrue()
        ->and($beans[ProdOnlyProperties::class]['properties'])->toBe(['endpoint' => 'https://prod.test'])
        ->and($broken['bound'])->toBeFalse()
        ->and($broken['properties'])->toBe([])
        ->and($broken['error'])->toBeString()
        ->and($broken['error'])->toContain('Missing required configuration property [mandatory]');
});

// Deterministic ordering matters more than it looks: the fallback manifest comes from a recursive directory
// walk, so without this sort the same application renders its rows in a different order on two machines.
it('sorts rows by class name so the payload does not depend on scan order', function () {
    $container = configPropsContainer([
        new ConfigPropertiesDescriptor(UnbindableProperties::class, 'unbindable'),
        new ConfigPropertiesDescriptor(DemoProperties::class, 'demo'),
        new ConfigPropertiesDescriptor(ProdOnlyProperties::class, 'prodonly'),
    ]);

    $beans = configPropsBeans((new ConfigPropsEndpoint($container))->handle(new EndpointRequest('GET', [])));

    expect(array_keys($beans))->toBe([DemoProperties::class, ProdOnlyProperties::class, UnbindableProperties::class]);
});

// A container with no bound manifest, no compiled config-properties.php and no firefly.scan.paths is the bare
// skeleton: the endpoint must answer an empty list, never blow up on the AppScan fallback.
it('answers an empty bean list when the application declares no config properties', function () {
    $container = new Container;
    $container->instance('config', new Repository(['firefly' => []]));

    $response = (new ConfigPropsEndpoint($container))->handle(new EndpointRequest('GET', []));

    expect($response->status)->toBe(200)
        ->and($response->contentType)->toBe('application/json')
        ->and($response->body)->toBe(['beans' => []]);
});

it('is exposed as the configprops endpoint id', function () {
    $endpoint = new ConfigPropsEndpoint(new Container);

    expect($endpoint->endpointId())->toBe('configprops')
        ->and($endpoint->enabled())->toBeTrue();
});
