<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Tests\Browser\Support\AdminDashboardBrowserTestCase;
use Pest\Browser\Enums\BrowserType;
use Pest\Browser\Playwright\Playwright;

pest()->extend(AdminDashboardBrowserTestCase::class);

it('navigates the complete catalogue and bounded focus with JavaScript disabled', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    $page = visit('/firefly/graph', ['javaScriptEnabled' => false])->assertSee('Bean explorer');
    $page->fill('q', 'OrderService');
    $page->keys('.bx-search button[type=submit]', 'Enter');
    $page->assertSee('Search results');
    $page->keys('td.t-qual a', 'Enter');
    $page->assertQueryStringHas('bean', 'App\\Orders\\OrderService')
        ->assertSee('All direct relations')->assertSee('Dependencies')
        ->screenshot(filename: 'admin-bean-focus-nojs');

});

it('keeps fixed geometry and scrolling headings aligned on a phone', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    $page = visit('/firefly/graph?bean=App%5COrders%5COrderService&depth=3')->on()->mobile()
        ->assertSee('All direct relations')
        ->assertScript(<<<'JS'
            (() => {
                const wrap = document.querySelector('.bx');
                const drawing = document.querySelector('.bx-drawing');
                const heads = document.querySelector('.bx-heads');
                if (!wrap || !drawing || !heads) return 'missing focus';
                wrap.scrollLeft = 220;
                const nodes = [...document.querySelectorAll('.bx-node')];
                return drawing.getBoundingClientRect().width === 1400
                    && drawing.getBoundingClientRect().height <= 728
                    && nodes.length <= 72 && nodes.every(n => n.getBoundingClientRect().width === 176 && n.getBoundingClientRect().height === 38)
                    && Math.abs(heads.getBoundingClientRect().left - drawing.getBoundingClientRect().left) < 1
                    && document.documentElement.scrollWidth <= document.documentElement.clientWidth;
            })()
            JS, true)
        ->assertNoJavaScriptErrors()->screenshot(filename: 'admin-bean-focus-mobile');
    $page->script("document.querySelector('.bx').scrollIntoView({block:'start'});document.querySelector('.bx').scrollLeft=document.querySelector('.is-focus').offsetLeft-80");
    $page->screenshot(filename: 'admin-bean-focus-mobile-drawing');
});

it('tabs through native bean links with visible focus and activates the selected neighbour', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    $page = visit('/firefly/graph?bean=App%5COrders%5COrderService');
    // Safari on macOS uses Option-Tab to include links in native keyboard navigation.
    $nextLink = Playwright::defaultBrowserType() === BrowserType::SAFARI ? 'Alt+Tab' : 'Tab';
    $page->keys('.bx', $nextLink)->assertScript(<<<'JS'
        (() => {
            const focus = document.activeElement;
            return focus.matches('a.bx-node') && getComputedStyle(focus).outlineStyle !== 'none'
                && parseFloat(getComputedStyle(focus).outlineWidth) >= 2;
        })()
        JS, true)->keys('a.bx-node:focus', 'Enter')->assertSee('All direct relations')->assertNoJavaScriptErrors();
});

it('keeps bean labels at readable contrast in both themes', function (string $theme): void {
    /** @var AdminDashboardBrowserTestCase $this */
    $page = visit('/firefly/graph?bean=App%5COrders%5COrderService', ['colorScheme' => $theme]);
    $page->assertScript(<<<'JS'
        (() => {
            function lum(rgb) { const c = rgb.match(/[\d.]+/g).slice(0,3).map(Number).map(v => {v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4}); return c[0]*.2126+c[1]*.7152+c[2]*.0722; }
            return [...document.querySelectorAll('.bx-name,.bx-sub,.bx-sigil')].every(el => {
                const a=lum(getComputedStyle(el).color), b=lum(getComputedStyle(el.closest('.bx-node')).backgroundColor);
                return (Math.max(a,b)+.05)/(Math.min(a,b)+.05)>=4.5 && parseFloat(getComputedStyle(el).fontSize)>=11;
            }) && document.querySelectorAll('.bx-node').length > 0;
        })()
        JS, true)->assertNoJavaScriptErrors()->screenshot(filename: 'admin-bean-focus-'.$theme);
})->with(['light', 'dark']);

it('keeps a two thousand dependent hub bounded and follows its exact overflow list', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    $this->app()->make(ActuatorRegistry::class)->register(new class implements ActuatorEndpoint
    {
        public function endpointId(): string
        {
            return 'beans';
        }

        public function enabled(): bool
        {
            return true;
        }

        public function handle(EndpointRequest $request): EndpointResponse
        {
            $rows = [['class' => 'Dense\\Core\\Hub']];
            for ($i = 0; $i < 2000; $i++) {
                $rows[] = ['class' => 'Dense\\Consumers\\Bean'.$i, 'dependencies' => ['Dense\\Core\\Hub']];
            }

            return EndpointResponse::json(['beans' => $rows]);
        }
    });
    $page = visit('/firefly/graph?bean=Dense%5CCore%5CHub&in_q=NoMatch')
        ->assertSee('+1984 more')
        ->assertScript("document.querySelectorAll('.bx-node').length", 17)
        ->assertScript("document.querySelector('.bx-drawing').getBoundingClientRect().height", 728)
        ->screenshot(filename: 'admin-bean-dense')
        ->click('a[href*="neighbor=Dense"]')
        ->assertQueryStringMissing('in_q')->assertSee('1–50 of 2,000')->assertNoJavaScriptErrors();
    $page->script("document.querySelector('#bx-lists').scrollIntoView({block:'start'})");
    $page->screenshot(filename: 'admin-bean-dense-relations');
});

it('shows module ports and bounded module coupling with navigable identities', function (): void {
    /** @var AdminDashboardBrowserTestCase $this */
    visit('/firefly/graph')->assertSee('Start here')->assertSee('Modules')->assertNoJavaScriptErrors()->screenshot(filename: 'admin-bean-landing');
    $page = visit('/firefly/graph?module=App%5COrders')->assertSee('Module neighbourhood')->assertSee('Module coupling')->assertNoJavaScriptErrors();
    $page->script("document.querySelector('.bx').scrollIntoView({block:'start'})");
    $page->assertScript("document.querySelectorAll('.bx-node[data-id^=\"module:\"]').length > 0", true)
        ->screenshot(filename: 'admin-bean-module');
});
