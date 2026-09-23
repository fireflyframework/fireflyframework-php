<?php

declare(strict_types=1);

use Firefly\Security\Tests\Support\SecuredHealthCapstoneTestCase;

uses(SecuredHealthCapstoneTestCase::class);

it('withholds health components from an anonymous scrape', function () {
    /** @var SecuredHealthCapstoneTestCase $this */
    $body = $this->getJson('/actuator/health')->assertOk()->json();

    expect($body)->toHaveKey('status')->not->toHaveKey('components');
});

it('publishes them to a principal holding the configured role', function () {
    /** @var SecuredHealthCapstoneTestCase $this */
    $body = $this->signIn(['ROLE_ACTUATOR'])->getJson('/actuator/health')->assertOk()->json();

    expect($body)->toHaveKey('components');
});

it('withholds them from an authenticated principal without the role', function () {
    /** @var SecuredHealthCapstoneTestCase $this */
    $body = $this->signIn(['ROLE_USER'])->getJson('/actuator/health')->assertOk()->json();

    expect($body)->toHaveKey('status')->not->toHaveKey('components');
});

it('publishes them to a principal whose role implies the configured one', function () {
    /** @var SecuredHealthCapstoneTestCase $this */
    $body = $this->signIn(['ROLE_ADMIN'])->getJson('/actuator/health')->assertOk()->json();

    expect($body)->toHaveKey('components');
});

/**
 * The probe must survive a MISTYPED roles key. `roles` is a brand-new list key, its two neighbours in the
 * same `firefly.management.*` block (`endpoints.web.exposure.include`, `endpoint.health.group.{name}.include`)
 * are CSV strings, and Spring's own property is `management.endpoint.health.roles=ACTUATOR,ADMIN` — so a
 * string is the likely spelling, not an exotic one. showDetails() sits OUTSIDE HealthEndpoint::readFailSafe(),
 * so a throw from this read escapes handle() and ActuatorDispatchAction renders it as a 500 problem document:
 * a config typo would take the liveness probe down. These two cases are at HTTP level for that reason — the
 * unit test proves the decision, only the pipeline proves the status code.
 */
it('answers the probe, not a 500, when roles is spelled as the CSV string its neighbours use', function () {
    /** @var SecuredHealthCapstoneTestCase $this */
    config()->set('firefly.management.endpoint.health.roles', 'ADMIN,ACTUATOR');

    $body = $this->signIn(['ROLE_ACTUATOR'])->getJson('/actuator/health')->assertOk()->json();

    expect($body)->toHaveKey('components');
});

it('answers the probe, not a 500, when roles holds a value that is no list of names at all', function () {
    /** @var SecuredHealthCapstoneTestCase $this */
    config()->set('firefly.management.endpoint.health.roles', 0);

    $body = $this->signIn(['ROLE_ADMIN'])->getJson('/actuator/health')->assertOk()->json();

    // Refused, because an unreadable restriction is still a restriction — see the unit test for why that is
    // the only direction this gate fails in.
    expect($body)->toHaveKey('status')->not->toHaveKey('components');
});
