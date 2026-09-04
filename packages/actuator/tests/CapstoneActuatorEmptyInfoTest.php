<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\EmptyInfoActuatorCapstoneTestCase;

uses(EmptyInfoActuatorCapstoneTestCase::class);

/**
 * An endpoint body is a JSON OBJECT by contract, but PHP encodes the empty array as `[]`. /actuator/info with
 * no InfoContributor therefore answered `[]` — an array where every client, and every other response from the
 * same endpoint, expects an object, which breaks a typed client deserialising into a map.
 *
 * This assertion used to live in CapstoneActuatorIntegrationTest, where /actuator/info was empty by default.
 * RuntimeInfoContributor made it non-empty by default (which is the point — the dashboard was rendering an
 * apology), so the empty case now needs a boot that deliberately removes it. The rendering rule itself is
 * unchanged and still worth pinning: it protects every OTHER endpoint that can legitimately return nothing.
 */
it('renders an empty endpoint body as {} rather than []', function () {
    /** @var EmptyInfoActuatorCapstoneTestCase $this */
    $response = $this->get('/actuator/info');

    $response->assertStatus(200);
    expect($response->getContent())->toBe('{}');
});
