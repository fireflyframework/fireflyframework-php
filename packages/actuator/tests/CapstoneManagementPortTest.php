<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\ManagementPortCapstoneTestCase;

/**
 * The end-to-end contract of firefly.management.server.port, over the REAL HTTP kernel: with a management port
 * configured, the actuator answers on that port and NOWHERE else.
 *
 * This is the half of the feature PHP can actually enforce. The second listening socket is the deployment's job (a
 * second PHP-FPM pool, a second container, a proxy rule — or `php artisan firefly:management:serve` locally); what
 * the framework guarantees, and what these cases pin, is that the application port stops serving the actuator the
 * moment a management port exists. Routes are still MOUNTED — one process, one Router — so every case here is
 * proving a request-time refusal, not an absent route.
 */
uses(ManagementPortCapstoneTestCase::class);

it('404s the health endpoint on the application port', function () {
    /** @var ManagementPortCapstoneTestCase $this */
    $this->getJson('/actuator/health')->assertStatus(404);
});

it('serves the health endpoint on the management port', function () {
    /** @var ManagementPortCapstoneTestCase $this */
    $this->getJson($this->onManagementPort('/actuator/health'))
        ->assertStatus(200)
        ->assertJsonPath('status', 'UP');
});

// An index answering 200 with an empty _links on the application port would confirm the actuator exists somewhere,
// which is precisely the disclosure the management port is there to stop.
it('404s the HAL index on the application port and serves it on the management port', function () {
    /** @var ManagementPortCapstoneTestCase $this */
    $this->getJson('/actuator')->assertStatus(404);

    $this->getJson($this->onManagementPort('/actuator'))
        ->assertStatus(200)
        ->assertJsonPath('_links.health.href', 'http://localhost:9001/actuator/health');
});

// The refusal must be indistinguishable from "no such route", so a scan of the public port learns nothing. The
// actuator's own 404 is RFC-9457 problem+json with RESOURCE_NOT_FOUND, exactly as an unexposed endpoint's is.
it('refuses with the same problem+json 404 an unexposed endpoint gets', function () {
    /** @var ManagementPortCapstoneTestCase $this */
    $onApp = $this->getJson('/actuator/health');
    $unexposed = $this->getJson($this->onManagementPort('/actuator/env'));

    expect($onApp->json('code'))->toBe('RESOURCE_NOT_FOUND')
        ->and($onApp->json('code'))->toBe($unexposed->json('code'))
        ->and($onApp->getStatusCode())->toBe($unexposed->getStatusCode())
        ->and($onApp->headers->get('Content-Type'))->toBe('application/problem+json');
});

// The guard must not turn into a general-purpose firewall: it refuses the ACTUATOR on the wrong port, and touches
// nothing else. Application routes are the listener's business, not PHP's — see ManagementServeCommand.
it('leaves non-actuator routes untouched on the application port', function () {
    /** @var ManagementPortCapstoneTestCase $this */
    $this->app()->make('router')->get('/ping', fn (): string => 'pong');

    expect($this->responseBody($this->get('/ping')->assertStatus(200)))->toBe('pong');
});
