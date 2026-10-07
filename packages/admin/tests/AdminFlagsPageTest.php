<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Admin\Tests\Support\AdminCapstoneTestCase;

uses(AdminCapstoneTestCase::class);

final class AdminFlagsScriptedEndpoint implements ActuatorEndpoint
{
    public static ?self $current = null;

    /** @var list<EndpointRequest> */
    public array $requests = [];

    public int $writeStatus = 200;

    public bool $pending = false;

    public bool $writable = true;

    public bool $writesEnabled = true;

    public bool $available = true;

    public bool $external = false;

    public function endpointId(): string
    {
        return 'flags';
    }

    public function enabled(): bool
    {
        return $this->available;
    }

    public function handle(EndpointRequest $request): EndpointResponse
    {
        $this->requests[] = $request;
        $key = $request->subPath[0] ?? null;

        if ($request->method === 'GET' && $key === null) {
            return EndpointResponse::text('{"provider":{"name":"firefly","status":"READY"},"writable":'.($this->writable ? 'true' : 'false').',"writesEnabled":'.($this->writesEnabled ? 'true' : 'false').','
                .'"sources":[{"name":"'.($this->external ? 'http' : 'config').'","enabled":true,"status":"UP","flags":2,"lastRefresh":"2026-10-01T09:30:00Z","error":null,"revision":null}],'
                .'"flags":[{"key":"banner","state":"ENABLED","type":"object","variants":["none","promo"],"defaultVariant":"none","targeting":false,"origin":"store","overrides":["config"],"metadata":{},"expired":false,"version":3},'
                .'{"key":"legacy-export","state":"ENABLED","type":"boolean","variants":["on","off"],"defaultVariant":"on","targeting":false,"origin":"config","overrides":[],"metadata":{"expires":"2025-01-01"},"expired":true,"version":null}]}', 200, 'application/json');
        }

        if ($request->method === 'GET' && $key === 'banner') {
            return EndpointResponse::text('{"key":"banner","definition":{"state":"ENABLED","variants":{"none":{},"promo":{"t":"x","w":1.0}},"defaultVariant":"none"},"origin":"store",'
                .'"layers":[{"source":"store","definition":{"state":"ENABLED","variants":{"none":{},"promo":{"t":"x","w":1.0}},"defaultVariant":"none"}}],'
                .'"version":3,"expired":false,"history":[{"id":9,"action":"put","actor":"ada","changedAt":"2026-10-01T09:30:00Z"}]}', 200, 'application/json');
        }

        if ($request->method === 'POST' && $request->rawBody !== null && str_contains($request->rawBody, '"evaluate"')) {
            return EndpointResponse::text('{"key":"banner","value":{"t":"x","w":1.0},"variant":"promo","reason":"TARGETING_MATCH","errorCode":null,"metadata":{}}', 200, 'application/json');
        }

        if ($request->method === 'POST') {
            if ($this->writeStatus !== 200) {
                return EndpointResponse::text('{"error":"conflict","message":"Flag [banner] is at version 4, the write expected 3."}', $this->writeStatus, 'application/json');
            }

            return EndpointResponse::text($this->pending ? '{"key":"banner","refreshPending":true}' : '{"key":"banner"}', 200, 'application/json');
        }

        return EndpointResponse::text('{"error":"unknown-flag","message":"No layer defines flag ['.$key.']."}', 404, 'application/json');
    }
}

function featureFlagsAdminScripted(): AdminFlagsScriptedEndpoint
{
    return AdminFlagsScriptedEndpoint::$current ?? throw new LogicException('beforeEach registers the scripted endpoint.');
}

function featureFlagsAdminLastRequest(): EndpointRequest
{
    $last = end(featureFlagsAdminScripted()->requests);

    return $last instanceof EndpointRequest ? $last : throw new LogicException('No endpoint request was recorded.');
}

beforeEach(function (): void {
    /** @var AdminCapstoneTestCase $this */
    AdminFlagsScriptedEndpoint::$current = new AdminFlagsScriptedEndpoint;
    /** @var ActuatorRegistry $registry */
    $registry = $this->app()->make(ActuatorRegistry::class);
    $registry->register(AdminFlagsScriptedEndpoint::$current);
});

it('lists flags with state, origin, expiry and a toggle', function (): void {
    /** @var AdminCapstoneTestCase $this */
    $this->get('/firefly/flags')->assertOk()->assertSee('Feature flags')->assertSee('banner')
        ->assertSee('legacy-export')->assertSee('expired')->assertSee('Disable')->assertSee('2026-10-01T09:30:00Z')
        ->assertSee('name="expectedVersion" value="3"', false);
});

it('shows exact definition shapes, layers and history', function (): void {
    /** @var AdminCapstoneTestCase $this */
    $this->get('/firefly/flags?flag=banner')->assertOk()->assertSee('"none": {}')->assertSee('"w": 1.0')
        ->assertSee('Save definition')->assertSee('Delete stored override')->assertSee('<td class="mono">ada</td>', false)
        ->assertSee('name="expectedVersion" value="3"', false);
});

it('answers 404 for an unknown key', function (): void {
    /** @var AdminCapstoneTestCase $this */
    $this->get('/firefly/flags?flag=nope')->assertNotFound()->assertSee('No such flag');
});

it('previews an evaluation with typed context and trusted admin origin', function (): void {
    /** @var AdminCapstoneTestCase $this */
    $this->get('/firefly/flags?flag=banner&evaluate=1&context='.rawurlencode('{"plan":"pro"}').'&targetingKey=u-1')
        ->assertOk()->assertSee('TARGETING_MATCH');

    $evaluate = featureFlagsAdminLastRequest();
    expect($evaluate->rawBody)->toBe('{"action":"evaluate","context":{"plan":"pro"},"targetingKey":"u-1"}')
        ->and($evaluate->query)->toBe([])
        ->and($evaluate->origin)->toBe('admin');
});

it('posts a toggle in-process and reports its result', function (): void {
    /** @var AdminCapstoneTestCase $this */
    $this->post('/firefly/flags', ['flag' => 'banner', 'op' => 'disable', 'back' => 'list'])
        ->assertRedirect('/firefly/flags')->assertSessionHas('data-message', 'Disabled [banner].');

    $write = featureFlagsAdminLastRequest();
    expect([$write->method, $write->subPath, $write->query, $write->body, $write->origin])
        ->toBe(['POST', ['banner'], [], ['action' => 'disable'], 'admin']);
});

it('sends the editor JSON untouched as the raw body', function (): void {
    /** @var AdminCapstoneTestCase $this */
    $this->post('/firefly/flags', ['flag' => 'banner', 'op' => 'put', 'expectedVersion' => '3',
        'definition' => '{"state":"ENABLED","variants":{"none":{}},"defaultVariant":"none"}'])
        ->assertRedirect('/firefly/flags?flag=banner')->assertSessionHas('data-message', 'Saved [banner].');

    expect(featureFlagsAdminLastRequest()->rawBody)
        ->toBe('{"action":"put","definition":{"state":"ENABLED","variants":{"none":{}},"defaultVariant":"none"},"expectedVersion":3}');
});

it('reports a pending refresh as a successful write with polling guidance', function (): void {
    /** @var AdminCapstoneTestCase $this */
    featureFlagsAdminScripted()->pending = true;
    $this->post('/firefly/flags', ['flag' => 'banner', 'op' => 'put', 'definition' => '{"state":"ENABLED","variants":{"none":{}},"defaultVariant":"none"}'])
        ->assertSessionHas('data-message', 'Write accepted for [banner]. Refresh pending; check this page again for visibility.');
});

it('reports endpoint refusals and invalid definitions', function (): void {
    /** @var AdminCapstoneTestCase $this */
    featureFlagsAdminScripted()->writeStatus = 409;
    $this->post('/firefly/flags', ['flag' => 'banner', 'op' => 'enable'])
        ->assertSessionHas('data-message', 'Refused: Flag [banner] is at version 4, the write expected 3.');
    $this->post('/firefly/flags', ['flag' => 'banner', 'op' => 'put', 'definition' => '[1, 2]'])
        ->assertSessionHas('data-message', 'Refused: the definition is not a JSON object.');
    $this->post('/firefly/flags', ['op' => 'enable'])
        ->assertSessionHas('data-message', 'Refused: no flag or no operation was named.');
});

it('shows an external source without offering controls when there is no store', function (): void {
    /** @var AdminCapstoneTestCase $this */
    featureFlagsAdminScripted()->writable = false;
    featureFlagsAdminScripted()->external = true;

    $this->get('/firefly/flags')->assertOk()->assertSee('Read-only: no flag store is configured.')
        ->assertSee('http')->assertDontSee('Disable');
    $this->get('/firefly/flags?flag=banner')->assertOk()->assertDontSee('Save definition')
        ->assertDontSee('Delete stored override')->assertSee('disabled', false);
});

it('hides controls when writes are disabled and hides the page when the endpoint is disabled', function (): void {
    /** @var AdminCapstoneTestCase $this */
    featureFlagsAdminScripted()->writesEnabled = false;
    $this->get('/firefly/flags')->assertOk()->assertSee('Read-only: enable')
        ->assertDontSee('Disable');

    featureFlagsAdminScripted()->available = false;
    $this->get('/firefly/flags')->assertNotFound()->assertSee('This page has no endpoint to read');
});

it('escapes an unknown key before placing it in the page', function (): void {
    /** @var AdminCapstoneTestCase $this */
    $this->get('/firefly/flags?flag='.rawurlencode('<script>alert(1)</script>'))
        ->assertNotFound()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('rejects a tokenless POST through the real CSRF middleware', function (): void {
    /** @var AdminCapstoneTestCase $this */
    // Laravel bypasses CSRF in the testing environment. Switch only this application's environment so
    // this request takes the same middleware branch as deployed HTTP traffic.
    $this->app()->instance('env', 'local');
    $this->post('/firefly/flags', ['flag' => 'banner', 'op' => 'disable'])->assertStatus(419);

    expect(featureFlagsAdminScripted()->requests)->toBe([]);
});
