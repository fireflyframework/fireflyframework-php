<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Introspection\MappingsEndpoint;
use Firefly\Admin\AdminSettings;
use Firefly\Admin\Tests\Support\AdminTableCapstoneTestCase;
use Firefly\Admin\Tests\Support\Fixtures\BillingProperties;
use Firefly\Admin\Tests\Support\Fixtures\LedgerProperties;
use Firefly\Config\Config;
use Firefly\Config\Scanner\ConfigPropertiesDescriptor;
use Firefly\Config\Scanner\ConfigPropertiesManifest;
use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Web\Dispatch\HandlerMethodArgumentResolver;
use Firefly\Web\Dispatch\HandlerMethodArgumentResolvers;
use Firefly\Web\Dispatch\ResponseFactory;
use Firefly\Web\Exception\ExceptionHandlerDescriptor;
use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Firefly\Web\Http\JsonMessageConverter;
use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\HtmlString;

uses(AdminTableCapstoneTestCase::class);

it('opens a real detail permalink and carries listing state back without requiring scripts', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->get('/firefly/mappings?q=orders&sort=path&size=25')->assertOk()
        ->assertSee('data-route="GET /orders/{id}"', false)
        ->assertSee('route=GET%20%2Forders%2F%7Bid%7D#route-detail', false);
    $this->get('/firefly/mappings?q=orders&sort=path&size=25&route=GET%20%2Forders%2F%7Bid%7D')->assertOk()
        ->assertSee('id="route-detail" tabindex="-1"', false)
        ->assertSee('Back to routes')->assertSee('Request · what the caller sends')
        ->assertSee('href="/firefly/mappings?q=orders&amp;sort=path&amp;size=25"', false)
        ->assertSee('Same controller')->assertSee('Default success status');
});

it('returns a hard 404 for malformed and missing route selectors', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->get('/firefly/mappings?route[]=GET')->assertNotFound();
    $this->get('/firefly/mappings?route=GET%20%2Fmissing')->assertNotFound();
});

it('renders ordered bindings patterns and claimed resolver arguments without treating them as caller input', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $binding = static fn (string $name, string $kind, ?string $type = 'string'): array => ['name' => $name, 'kind' => $kind, 'key' => $name, 'type' => $type, 'required' => true, 'default' => null, 'valid' => false, 'properties' => []];
    $this->app()->instance(RouteManifest::class, new RouteManifest([
        new RouteDescriptor('POST', '/contract/{id}', 'App\\Contract', 'create', 201, 'contract', [
            [...$binding('id', 'path', 'int'), 'pattern' => '[0-9]+'], $binding('q', 'query'), $binding('X-Trace', 'header'), $binding('upload', 'file'),
            [...$binding('body', 'body', stdClass::class), 'valid' => true, 'properties' => ['title']], $binding('service', 'service', 'UnboundCollaborator'),
        ]),
    ]));
    $this->get('/firefly/mappings?route=POST%20%2Fcontract%2F%7Bid%7D')->assertOk()
        ->assertSee('That resource does not exist.')->assertSee('201')
        ->assertSee('MISSING_PARAMETER')->assertSee('UNBINDABLE_BODY')->assertSee((new ValidationException)->errorCode())
        ->assertSee('what the container supplies')->assertSee('UnboundCollaborator')->assertSee('title')
        ->assertSee('Security authorization is not inferred');
});

it('honors route detail and mappings page switches with hard refusals', function (string $key, mixed $value) {
    /** @var AdminTableCapstoneTestCase $this */
    $config = $this->app()->make(Repository::class);
    $config->set($key, $value);
    $this->app()->instance(AdminSettings::class, AdminSettings::fromConfig(new Config($config)));
    $this->get('/firefly/mappings?route=GET%20%2Forders')->assertNotFound();
    if ($key === 'firefly.admin.routes.detail') {
        $this->get('/firefly/mappings')->assertOk()->assertDontSee('data-route="', false);
    }
})->with([['firefly.admin.routes.detail', false], ['firefly.admin.pages.exclude', 'mappings'], ['firefly.management.endpoint.mappings.enabled', false]]);

it('keeps optional crosslinks behind destination gates and does not read detail collaborators on the listing', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $config = $this->app()->make(Repository::class);
    $config->set('firefly.admin.pages.exclude', 'graph,beans,configprops,http,metrics');
    $config->set('firefly.admin.routes.advice', false);
    $this->app()->instance(AdminSettings::class, AdminSettings::fromConfig(new Config($config)));
    $this->app()->bind(ProxyPlan::class, fn () => throw new RuntimeException('Advice disabled'));
    $this->get('/firefly/mappings')->assertOk();
    $this->get('/firefly/mappings?route=GET%20%2Forders')->assertOk()
        ->assertDontSee('href="/firefly/graph', false)->assertDontSee('href="/firefly/configprops', false)
        ->assertDontSee('href="/firefly/http', false)->assertDontSee('href="/firefly/metrics', false)
        ->assertDontSee('Advice · compiled contract');
});

it('badges shadowed list rows and displays both registrations with the last effective', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $routes = [new RouteDescriptor('GET', '/duplicate', 'App\\Old', 'index', 200, 'old', []), new RouteDescriptor('GET', '/duplicate', 'App\\New', 'index', 202, 'new', [])];
    $this->app()->instance(RouteManifest::class, new RouteManifest($routes));
    $this->app()->make(ActuatorRegistry::class)->register(new MappingsEndpoint(new RouteManifest($routes)));
    $this->get('/firefly/mappings')->assertOk()->assertSee('Shadowed');
    $this->get('/firefly/mappings?route=GET%20%2Fduplicate')->assertOk()->assertSee('Duplicate registrations')
        ->assertSee('App\\Old')->assertSee('App\\New')->assertSee('202')->assertSee('Effective');
});

it('uses the registered API viewer route and only actual configuration collaborators as joins', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->app()->make(Router::class)->get('/reference', fn () => 'viewer')->name('firefly.openapi.viewer');
    $type = BillingProperties::class;
    $this->app()->instance(RouteManifest::class, new RouteManifest([
        new RouteDescriptor('GET', '/configured', 'App\\Configured', 'index', 200, null, [['name' => 'config', 'kind' => 'service', 'key' => 'config', 'type' => $type, 'required' => true, 'default' => null, 'valid' => false, 'properties' => []]]),
    ]));
    $this->get('/firefly/mappings?route=GET%20%2Fconfigured')->assertOk()->assertSee('href="/reference"', false)
        ->assertSee('Configuration: BillingProperties')->assertSee('href="/firefly/http"', false);
});

it('renders a principal-style resolver claim and never calls resolve or derives query failures for it', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $resolvers = new HandlerMethodArgumentResolvers;
    $resolvers->add(new class implements HandlerMethodArgumentResolver
    {
        public function supports(array $binding): bool
        {
            return in_array('PrincipalAttribute', is_array($binding['attributes'] ?? null) ? $binding['attributes'] : [], true);
        }

        public function resolve(array $binding, Request $request): mixed
        {
            throw new RuntimeException('Inspection must never resolve a principal');
        }
    });
    $this->app()->instance(HandlerMethodArgumentResolvers::class, $resolvers);
    $this->app()->instance(RouteManifest::class, new RouteManifest([
        new RouteDescriptor('GET', '/principal', 'App\\PrincipalController', 'show', 200, null, [['name' => 'subject', 'kind' => 'query', 'key' => 'subject', 'type' => 'int', 'required' => true, 'default' => null, 'valid' => false, 'properties' => [], 'attributes' => ['PrincipalAttribute'], 'nullable' => true]]),
    ]));
    $this->get('/firefly/mappings?route=GET%20%2Fprincipal')->assertOk()->assertSee('Resolver claimed')
        ->assertSee('may be null')->assertSee('No caller-supplied arguments')
        ->assertDontSee('MISSING_PARAMETER')->assertDontSee('TYPE_CONVERSION_ERROR');
});

it('does not inspect resolvers advice or handlers for an ordinary route listing', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $app = $this->app();
    foreach ([HandlerMethodArgumentResolvers::class, ProxyPlan::class, ExceptionHandlerRegistry::class] as $class) {
        $app->bind($class, fn () => throw new RuntimeException('Detail collaborator resolved on listing'));
    }
    $this->get('/firefly/mappings')->assertOk()->assertSee('Mappings');
});

it('shows only matching exception advice and distinguishes an HTML stereotype', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $this->app()->instance(ExceptionHandlerRegistry::class, new ExceptionHandlerRegistry([
        new ExceptionHandlerDescriptor(RuntimeException::class, 'App\\Http\\WelcomeController', 'localFailure', false),
        new ExceptionHandlerDescriptor(Throwable::class, 'App\\GlobalAdvice', 'globalFailure', true),
        new ExceptionHandlerDescriptor(Throwable::class, 'App\\Unrelated', 'unrelatedFailure', false),
    ]));
    $this->get('/firefly/mappings?route=GET%20%2F')->assertOk()->assertSee('HTML stereotype')->assertSee('Declared')
        ->assertSee('localFailure')->assertSee('globalFailure')->assertDontSee('unrelatedFailure')
        ->assertSee('firefly.openapi.include-html');
});

it('follows configuration joins to the correctly filtered bound or unbound panel', function (bool $bound) {
    /** @var AdminTableCapstoneTestCase $this */
    $type = $bound ? BillingProperties::class : LedgerProperties::class;
    if ($bound) {
        $this->app()->instance(LedgerProperties::class, new LedgerProperties);
    }
    $this->app()->instance(ConfigPropertiesManifest::class, new ConfigPropertiesManifest([
        new ConfigPropertiesDescriptor(BillingProperties::class, 'billing'),
        new ConfigPropertiesDescriptor(LedgerProperties::class, 'ledger'),
        new ConfigPropertiesDescriptor(stdClass::class, 'unrelated'),
    ]));
    $this->app()->instance(RouteManifest::class, new RouteManifest([
        new RouteDescriptor('GET', '/configuration-link', 'App\\Configured', 'index', 200, null, [['name' => 'config', 'kind' => 'service', 'key' => 'config', 'type' => $type, 'required' => true, 'default' => null, 'valid' => false, 'properties' => []]]),
    ]));
    $body = (string) $this->get('/firefly/mappings?route=GET%20%2Fconfiguration-link')->assertOk()->getContent();
    preg_match('/href="([^"]+)">Configuration:/', $body, $matches);
    $url = html_entity_decode($matches[1] ?? '');
    expect($url)->toContain($bound ? 'props_q=' : 'unbound_q=');
    $destination = $this->get($url)->assertOk();
    if ($bound) {
        $destination->assertSee('BillingProperties')->assertDontSee('LedgerProperties')->assertSee('3 total');
    } else {
        $destination->assertSee('LedgerProperties')->assertDontSee('stdClass')->assertSee('1 total');
    }
})->with([true, false]);

it('describes HTML as declaration metadata because response negotiation follows the returned value', function (bool $html) {
    /** @var AdminTableCapstoneTestCase $this */
    $descriptor = new RouteDescriptor('GET', '/mixed-response', 'App\\MixedController', 'show', 200, null, [], html: $html);
    $factory = new ResponseFactory(new MessageConverterRegistry([new JsonMessageConverter]));
    $request = Request::create('/mixed-response', server: ['HTTP_ACCEPT' => 'application/json']);
    expect($factory->make(['ok' => true], $descriptor, $request)->headers->get('Content-Type'))->toBe('application/json')
        ->and($factory->make(new HtmlString('<p>A view-like return</p>'), $descriptor, $request)->headers->get('Content-Type'))->toBe('text/html; charset=UTF-8');
    $this->app()->instance(RouteManifest::class, new RouteManifest([$descriptor]));
    $this->get('/firefly/mappings?route=GET%20%2Fmixed-response')->assertOk()
        ->assertSee('HTML stereotype')->assertSee('The returned value and Accept determine the response.')
        ->assertDontSee('HTML page (text/html)');
})->with([true, false]);
