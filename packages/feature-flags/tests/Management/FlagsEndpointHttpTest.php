<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Tests\Support\ManagementEndpointTestCase;
use Illuminate\Config\Repository;

uses(ManagementEndpointTestCase::class);

it('honors the per-endpoint enable switch', function (): void {
    /** @var ManagementEndpointTestCase $this */
    $this->getJson('/actuator/flags')->assertOk();
    $this->app()->make(Repository::class)->set('firefly.management.endpoint.flags.enabled', false);
    $this->getJson('/actuator/flags')->assertNotFound();
});

it('lists numeric-looking flag keys as strings (RF3)', function (): void {
    /** @var ManagementEndpointTestCase $this */
    $response = $this->getJson('/actuator/flags')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and((string) $response->getContent())->toContain('"key":"0"')
        ->and((string) $response->getContent())->toContain('"key":"2024"')
        ->and((string) $response->getContent())->toContain('"provider":{"name":"firefly","status":"READY"}');
});

it('keeps {} and 1.0 through POST and GET /actuator/flags (RF4)', function (): void {
    /** @var ManagementEndpointTestCase $this */
    $this->call('POST', '/actuator/flags/banner', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        '{"action":"put","definition":{"state":"ENABLED","variants":{"none":{},"promo":{"t":"x"}},"defaultVariant":"none","metadata":{}}}')
        ->assertOk();

    expect((string) $this->getJson('/actuator/flags/banner')->assertOk()->getContent())
        ->toContain('"metadata":{}')
        ->toContain('"variants":{"none":{},"promo":{"t":"x"}}')
        ->and((string) $this->getJson('/actuator/flags/price')->assertOk()->getContent())
        ->toContain('"variants":{"a":1.0,"b":2.5}');
});

it('evaluates over HTTP and answers 404 with the contract body for an unknown key', function (): void {
    /** @var ManagementEndpointTestCase $this */
    $this->postJson('/actuator/flags/2024', ['action' => 'evaluate'])
        ->assertOk()
        ->assertJson(['key' => '2024', 'value' => 'v2', 'variant' => 'v2', 'reason' => 'STATIC', 'errorCode' => null]);

    $this->getJson('/actuator/flags/nope')->assertStatus(404)->assertJson(['error' => 'unknown-flag']);
});

it('rejects malformed JSON and preserves the portable error body', function (): void {
    /** @var ManagementEndpointTestCase $this */
    $response = $this->call('POST', '/actuator/flags/banner', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{bad');
    $response->assertStatus(400)->assertJsonStructure(['error', 'message'])->assertJson(['error' => 'bad-request']);
});
