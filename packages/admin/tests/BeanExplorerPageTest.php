<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Admin\AdminSettings;
use Firefly\Admin\Tests\Support\AdminCapstoneTestCase;

uses(AdminCapstoneTestCase::class);

beforeEach(function () {
    /** @var AdminCapstoneTestCase $this */
    $registry = $this->app()->make(ActuatorRegistry::class);
    $registry->register(new class implements ActuatorEndpoint
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
            $rows = [['class' => 'App\\Config', 'produces' => [['type' => 'Lib\\Module\\Product', 'method' => 'make', 'dependencies' => []]]]];
            for ($i = 0; $i < 80; $i++) {
                $rows[] = ['class' => 'App\\Consumer'.$i, 'dependencies' => ['Lib\\Module\\Product']];
            }

            return EndpointResponse::json(['beans' => $rows]);
        }
    });
});

it('opens a bounded landing and finds factory products in the complete catalogue', function () {
    /** @var AdminCapstoneTestCase $this */
    $this->get('/firefly/graph')->assertOk()->assertSee('Bean explorer')->assertSee('Start here')->assertDontSee('id="g-svg"', false);
    $this->get('/firefly/beans?q=Product')->assertOk()->assertSee('Product')->assertSee('bean=Lib', false);
    $this->get('/firefly/graph?q=Product')->assertOk()->assertSee('Search results')->assertSee('Product');
});

it('renders bounded focus links and exact paginated neighbour lists without scripts', function () {
    /** @var AdminCapstoneTestCase $this */
    $html = $this->get('/firefly/graph?bean=Lib%5CModule%5CProduct&depth=2&dir=both')->assertOk()->assertSee('Depends on')->assertSee('Depended on by')->assertSee('+65 more')->getContent();
    expect($html)->toContain('class="bx-node', 'role="group"', 'neighbor=Lib', 'rel=')
        ->not->toContain('MIN_FIT', 'setPointerCapture');
    $this->get('/firefly/graph?bean=Lib%5CModule%5CProduct&neighbor=Lib%5CModule%5CProduct&rel=in&in_size=25&in_page=2')
        ->assertOk()->assertSee('in_page=3', false)->assertSee('neighbor=Lib', false);
});

it('renders module imports and gracefully handles a missing bean', function () {
    /** @var AdminCapstoneTestCase $this */
    $this->get('/firefly/graph?module=App')->assertOk()->assertSee('Module coupling')->assertSee('Concrete')->assertSee('Lib');
    $this->get('/firefly/graph?bean=Missing')->assertOk()->assertSee('Bean not found');
});

it('retains every positive and negative condition for the factory declaring a selected product', function () {
    /** @var AdminCapstoneTestCase $this */
    $this->app()->make(ActuatorRegistry::class)->register(new class implements ActuatorEndpoint
    {
        public function endpointId(): string
        {
            return 'conditions';
        }

        public function enabled(): bool
        {
            return true;
        }

        public function handle(EndpointRequest $request): EndpointResponse
        {
            return EndpointResponse::json(['positiveMatches' => [['class' => 'App\\Config', 'condition' => 'One'], ['class' => 'App\\Config', 'condition' => 'Two']], 'negativeMatches' => [['class' => 'App\\Config', 'condition' => 'Three'], ['class' => 'App\\Config', 'condition' => 'Four']]]);
        }
    });
    $this->get('/firefly/graph?bean=Lib%5CModule%5CProduct')->assertOk()->assertSee('One')->assertSee('Two')->assertSee('Three')->assertSee('Four')->assertSee('Backed off');
});

it('resets qualified paging on a neighbour sort without losing the selected bean', function () {
    /** @var AdminCapstoneTestCase $this */
    $html = $this->get('/firefly/graph?bean=Lib%5CModule%5CProduct&depth=3&in_page=2&in_size=25')->assertOk()->getContent();
    preg_match('/href="([^"]*in_sort=id[^"]*)"/', (string) $html, $match);
    expect($match[1] ?? '')->not->toBe('')->not->toContain('in_page=2')->toContain('bean=Lib', 'depth=3', 'in_size=25');
});

it('renders module coupling through the shared paginated column system', function () {
    /** @var AdminCapstoneTestCase $this */
    $this->get('/firefly/graph?module=App')->assertOk()->assertSee('coupling_sort=weight', false)->assertSee('aria-sort=', false)->assertSee('Module coupling');
});

it('bounds giant cyclic membership and unresolved lists as paginated tables', function () {
    /** @var AdminCapstoneTestCase $this */
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
            $rows = [];
            for ($i = 0; $i < 5000; $i++) {
                $rows[] = ['class' => 'Cycle'.$i, 'dependencies' => ['Cycle'.(($i + 1) % 5000), 'Missing'.$i]];
            }

            return EndpointResponse::json(['beans' => $rows]);
        }
    });
    $html = (string) $this->get('/firefly/graph?bean=Cycle0')->assertOk()->assertSee('cycles_page=2', false)->assertSee('unresolved_page=2', false)->getContent();
    expect(substr_count($html, '<tr>'))->toBeLessThan(200)->and(strlen($html))->toBeLessThan(200000);
});

it('paginates complete long entry chains and explicitly abbreviates the preview', function () {
    /** @var AdminCapstoneTestCase $this */
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
            $rows = [];
            for ($i = 0; $i < 5000; $i++) {
                $rows[] = ['class' => 'Chain'.$i, 'dependencies' => $i < 4999 ? ['Chain'.($i + 1)] : []];
            }

            return EndpointResponse::json(['beans' => $rows]);
        }
    });
    $html = (string) $this->get('/firefly/graph?bean=Chain4999')->assertOk()->assertSee('4984 further hops')->assertSee('paths_page=2', false)->getContent();
    expect(substr_count($html, '<tr>'))->toBeLessThan(150)->and(strlen($html))->toBeLessThan(200000);
});

it('omits links into an excluded catalogue', function () {
    /** @var AdminCapstoneTestCase $this */
    $this->app()->instance(AdminSettings::class, new AdminSettings(true, 'firefly', 'Test', excludedPages: ['beans']));
    $this->get('/firefly/graph?bean=Lib%5CModule%5CProduct')->assertOk()->assertDontSee('Full catalogue')->assertDontSee('See it in the container');
});

it('renders focus when the optional conditions endpoint is unavailable', function () {
    /** @var AdminCapstoneTestCase $this */
    $this->app()->make(ActuatorRegistry::class)->register(new class implements ActuatorEndpoint
    {
        public function endpointId(): string
        {
            return 'conditions';
        }

        public function enabled(): bool
        {
            return false;
        }

        public function handle(EndpointRequest $request): EndpointResponse
        {
            throw new RuntimeException('Disabled endpoint must not be read');
        }
    });
    $this->get('/firefly/graph?bean=Lib%5CModule%5CProduct')->assertOk()->assertSee('All direct relations');
});
