<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Fixtures\DemoProperties;
use Firefly\Actuator\Tests\Fixtures\ProdOnlyProperties;
use Firefly\Actuator\Tests\Fixtures\UnbindableProperties;
use Firefly\Actuator\Tests\Support\IntrospectionExposedCapstoneTestCase;

uses(IntrospectionExposedCapstoneTestCase::class);

/**
 * The HTTP-level half of the two new introspection endpoints. The unit tests pin the payload shapes; this file
 * pins that the endpoints are actually WIRED — discovered as #[Component] ActuatorEndpoint beans out of the
 * compiled manifest, resolved once by ActuatorRouteRegistrar into ActuatorRegistry, and dispatched by
 * ActuatorDispatchAction under the base path. Neither could be caught by constructing the endpoint directly,
 * and that exact gap is what once left InfoEndpoint unreachable in every real boot (see its docblock).
 */
it('serves /actuator/configprops with resolved, masked values', function () {
    /** @var IntrospectionExposedCapstoneTestCase $this */
    $response = $this->getJson('/actuator/configprops');

    $response->assertStatus(200)
        ->assertJsonPath('beans.'.DemoProperties::class.'.class', DemoProperties::class)
        ->assertJsonPath('beans.'.DemoProperties::class.'.prefix', 'demo')
        ->assertJsonPath('beans.'.DemoProperties::class.'.bound', true)
        ->assertJsonPath('beans.'.DemoProperties::class.'.error', null)
        ->assertJsonPath('beans.'.DemoProperties::class.'.properties.name', 'checkout')
        // '3' in config, int in the DTO: the row shows the value the application will actually use.
        ->assertJsonPath('beans.'.DemoProperties::class.'.properties.retries', 3)
        ->assertJsonPath('beans.'.DemoProperties::class.'.properties.apiToken', '******')
        ->assertJsonPath('beans.'.DemoProperties::class.'.properties.signingKeys', '******')
        ->assertJsonPath('beans.'.DemoProperties::class.'.properties.endpoint.url', 'https://demo.test')
        ->assertJsonPath('beans.'.DemoProperties::class.'.properties.endpoint.password', '******');

    expect($this->responseBody($response))->not->toContain('super-secret-token')
        ->and($this->responseBody($response))->not->toContain('PRIVATE-A')
        ->and($this->responseBody($response))->not->toContain('hunter2');
});

// Both degraded rows, over the REAL registrar rather than a hand-built manifest. ProdOnlyProperties carries
// #[Profile('prod')] and the active profiles do not include it, so ConfigRegistrar never bound it;
// UnbindableProperties has a required property with nothing under its prefix, so resolving it throws. Neither
// may take the endpoint down with it, and neither may vanish from the list — "declared but not bound" and
// "declared and broken" are exactly the two states an operator hunting a missing setting needs to see.
it('reports a profile-gated and an unbindable DTO as their own rows without failing the endpoint', function () {
    /** @var IntrospectionExposedCapstoneTestCase $this */
    $this->getJson('/actuator/configprops')
        ->assertStatus(200)
        ->assertJsonPath('beans.'.ProdOnlyProperties::class.'.profiles', ['prod'])
        ->assertJsonPath('beans.'.ProdOnlyProperties::class.'.bound', false)
        ->assertJsonPath('beans.'.ProdOnlyProperties::class.'.properties', [])
        ->assertJsonPath('beans.'.ProdOnlyProperties::class.'.error', null)
        ->assertJsonPath('beans.'.UnbindableProperties::class.'.bound', false)
        ->assertJsonPath('beans.'.UnbindableProperties::class.'.error', fn (mixed $e): bool => is_string($e) && str_contains($e, 'Missing required configuration property [mandatory]'));
});

it('serves /actuator/caches and a single store on a sub-path', function () {
    /** @var IntrospectionExposedCapstoneTestCase $this */
    $this->getJson('/actuator/caches')
        ->assertStatus(200)
        ->assertJsonPath('default', 'array')
        ->assertJsonPath('caches.array', ['name' => 'array', 'driver' => 'array', 'default' => true])
        ->assertJsonPath('caches.redis', ['name' => 'redis', 'driver' => 'redis', 'default' => false]);

    $this->getJson('/actuator/caches/redis')
        ->assertStatus(200)
        ->assertJsonPath('driver', 'redis')
        ->assertJsonPath('default', false);
});

it('404s an unknown cache store and refuses a POST through the real dispatcher', function () {
    /** @var IntrospectionExposedCapstoneTestCase $this */
    $this->getJson('/actuator/caches/memcached')->assertStatus(404);
    $this->postJson('/actuator/caches/redis')->assertStatus(404);
});

it('advertises both endpoints on the HAL index once exposed', function () {
    /** @var IntrospectionExposedCapstoneTestCase $this */
    $this->getJson('/actuator')
        ->assertStatus(200)
        ->assertJsonPath('_links.configprops.href', fn (mixed $h): bool => is_string($h) && str_ends_with($h, '/actuator/configprops'))
        ->assertJsonPath('_links.caches.href', fn (mixed $h): bool => is_string($h) && str_ends_with($h, '/actuator/caches'));
});
