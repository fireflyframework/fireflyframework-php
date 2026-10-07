<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Tests\Browser\Support\BrowserTestCase;

pest()->extend(BrowserTestCase::class);

final readonly class BrowserPendingFlagsEndpoint implements ActuatorEndpoint
{
    public function __construct(private ActuatorEndpoint $delegate) {}

    public function endpointId(): string
    {
        return 'flags';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): ?EndpointResponse
    {
        return $request->method === 'POST'
            ? EndpointResponse::text('{"key":"checkout-flow","refreshPending":true}', 200, 'application/json')
            : $this->delegate->handle($request);
    }
}

it('lists flags and toggles one through its form', function (): void {
    /** @var BrowserTestCase $this */
    $form = 'form:has(input[name="flag"][value="beta-banner"]):has(input[name="back"])';
    $page = visit('/firefly/flags');

    $page->assertScript("performance.getEntriesByType('navigation')[0].responseStatus", 200)
        ->assertSee('Feature flags')->assertSee('beta-banner')->assertSee('legacy-export')->assertSee('expired')
        ->assertNoJavaScriptErrors()->screenshot(filename: 'flags');

    $page->click($form.' button')->assertSee('Disabled [beta-banner].')
        ->assertSeeIn($form.' button', 'Enable')->assertNoJavaScriptErrors();
});

it('edits a definition, evaluates it and shows trusted audit history', function (): void {
    /** @var BrowserTestCase $this */
    $page = visit('/firefly/flags?flag=checkout-flow');

    $page->assertSee('checkout-flow')->assertSee('Save definition')->assertNoJavaScriptErrors();

    $page->fill('#flag-definition', '{"state":"ENABLED","variants":{"v1":"v1","v2":"v2"},"defaultVariant":"v2","metadata":{"owner":"payments","expires":"2099-12-31"}}')
        ->press('Save definition')->assertSee('Saved [checkout-flow].')
        ->assertSeeIn('#flag-history', 'admin')->assertNoJavaScriptErrors()->screenshot(filename: 'flags-edited');

    $page->fill('#flag-context', '{"plan":"basic"}')->fill('#flag-targeting-key', 'u-1')
        ->press('Evaluate')->assertSeeIn('[data-evaluation="value"]', '"v2"')
        ->assertSeeIn('[data-evaluation="reason"]', 'STATIC')->assertNoJavaScriptErrors();
});

it('keeps flag controls reachable on a phone', function (): void {
    /** @var BrowserTestCase $this */
    $page = visit('/firefly/flags?flag=checkout-flow')->on()->mobile()
        ->assertSee('Feature flags')->assertSee('Save definition')->assertSee('Evaluate')
        ->assertNoJavaScriptErrors()->screenshot(filename: 'flags-mobile');

    $page->script("document.documentElement.style.zoom = '200%'");
    $page->keys('#flag-context', 'Tab')
        ->assertScript('document.activeElement.id', 'flag-targeting-key')
        ->keys('#flag-targeting-key', 'Tab')
        ->assertScript('document.activeElement.textContent.trim()', 'Evaluate')
        ->assertNoJavaScriptErrors()->screenshot(filename: 'flags-mobile-zoom');
});

it('shows the pending refresh receipt after a browser form submission', function (): void {
    /** @var BrowserTestCase $this */
    /** @var ActuatorRegistry $registry */
    $registry = $this->app()->make(ActuatorRegistry::class);
    $delegate = $registry->get('flags') ?? throw new LogicException('The flags endpoint is not registered.');
    $registry->register(new BrowserPendingFlagsEndpoint($delegate));

    visit('/firefly/flags?flag=checkout-flow')->press('Save definition')
        ->assertSee('Write accepted for [checkout-flow]. Refresh pending; check this page again for visibility.')
        ->assertDontSee('Deleted [checkout-flow].')
        ->assertNoJavaScriptErrors()->screenshot(filename: 'flags-refresh-pending');
});

it('changes the default, refuses a stale edit and deletes the stored override', function (): void {
    /** @var BrowserTestCase $this */
    $page = visit('/firefly/flags?flag=checkout-flow');

    $page->select('variant', 'v2')->press('Set default')
        ->assertSee('Set the default variant of [checkout-flow].')
        ->assertSee('Delete stored override');

    $page->script("document.querySelector('form:has(input[name=\"op\"][value=\"put\"]) input[name=\"expectedVersion\"]').value = '0'");
    $page->press('Save definition')->assertSee('Refused:')
        ->assertSee('expected 0');

    $page->press('Delete stored override')
        ->assertSee('Deleted the stored override of [checkout-flow].')
        ->assertNoJavaScriptErrors()->screenshot(filename: 'flags-override-deleted');
});
