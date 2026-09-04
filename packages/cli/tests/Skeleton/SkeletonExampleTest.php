<?php

declare(strict_types=1);

use Firefly\Cli\Cache\FireflyCachePaths;
use Firefly\Cli\Tests\Support\SkeletonApp;
use Firefly\Cli\Tests\Support\SkeletonExampleTestCase;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteManifest;

/**
 * The shipped example, under the default gate.
 *
 * `composer create-project firefly/skeleton` hands a developer skeleton/app and then runs `firefly:cache`
 * over it. Until now nothing in the monorepo's default suite compiled that directory or served a single one
 * of its routes: the skeleton is its own composer package, its tests/ live outside every configured suite,
 * and CreateProjectOfflineTest — the only test that touched it — is in the excluded `createproject` group
 * and asserted merely that a routes manifest FILE had been written. The example could have degraded to a
 * 500 on every endpoint with every gate still green.
 *
 * So this file compiles skeleton/app with the real ManifestCacheWriter (what `firefly:cache` runs) and then
 * drives the sample CRUD resource over the real HTTP pipeline: routing, path/query binding and coercion,
 * #[RequestBody] hydration of a NESTED DTO and a LIST of DTOs, the #[Valid] cascade's 422, the service's
 * RFC-7807 404, and the declared 201/204 statuses.
 */
uses(SkeletonExampleTestCase::class);

// Registered at file-load, before any test runs: every Firefly scanner discovers classes through
// class_exists(), and the monorepo's composer autoloader maps `App\` at a Pint directory that does not
// exist — so without this the compile below would produce empty manifests and every assertion would pass
// for entirely the wrong reason.
SkeletonApp::register();

it('compiles the shipped skeleton app with the real firefly:cache writer', function (): void {
    $dir = (string) SkeletonExampleTestCase::$cacheDir;

    foreach ([FireflyCachePaths::COMPONENT, FireflyCachePaths::CONTEXT, FireflyCachePaths::ROUTES, FireflyCachePaths::CONSTRAINTS, FireflyCachePaths::CONFIG_PROPERTIES] as $basename) {
        expect(is_file($dir.'/'.$basename))->toBeTrue("expected the skeleton compile to write {$basename}");
    }

    // Read the artifacts back through the framework's own loaders — the same call the cached boot makes.
    $components = array_map(
        static fn (ComponentDescriptor $d): string => $d->class,
        ComponentManifest::load($dir.'/'.FireflyCachePaths::COMPONENT)->components,
    );

    expect($components)
        ->toContain('App\\Http\\OrderController')
        ->toContain('App\\Orders\\OrderService')
        ->toContain('App\\Orders\\OrderRepository');

    // The DTOs carry no stereotype and must NOT become beans — they are hydrated per request, not injected.
    expect($components)
        ->not->toContain('App\\Http\\OrderRequest')
        ->not->toContain('App\\Http\\AddressPayload')
        ->not->toContain('App\\Http\\OrderLinePayload');
});

it('compiles all five REST actions of the sample resource into the route manifest', function (): void {
    $routes = RouteManifest::load((string) SkeletonExampleTestCase::$cacheDir.'/'.FireflyCachePaths::ROUTES)->all();

    $orders = [];
    foreach ($routes as $route) {
        if ($route->controllerClass === 'App\\Http\\OrderController') {
            $orders[$route->methodName] = $route->httpMethod.' '.$route->path;
        }
    }

    // The base path is the class-level #[RequestMapping], joined with each action's own suffix — and it is
    // the plural, kebab-cased collection path, never the class name.
    expect($orders)->toBe([
        'index' => 'GET /orders',
        'show' => 'GET /orders/{id}',
        'store' => 'POST /orders',
        'update' => 'PUT /orders/{id}',
        'destroy' => 'DELETE /orders/{id}',
    ]);

    $statuses = [];
    foreach ($routes as $route) {
        if ($route->controllerClass === 'App\\Http\\OrderController') {
            $statuses[$route->methodName] = $route->status;
        }
    }

    // 201 and 204 are declared on the mapping; nothing in the controller builds a response by hand.
    expect($statuses['store'])->toBe(201)
        ->and($statuses['destroy'])->toBe(204)
        ->and($statuses['index'])->toBe(200);
});

it('serves the whole CRUD lifecycle over the real HTTP pipeline', function (): void {
    /** @var SkeletonExampleTestCase $this */

    // CREATE — 201, and the nested DTO plus the list of DTOs both hydrated: `total` is computed from the
    // OrderLine objects the resolver built, so a raw sub-array reaching the domain would show up here.
    $created = $this->postJson('/orders', SkeletonApp::orderBody());
    $created->assertStatus(201)
        ->assertJsonPath('customer', 'Ada Lovelace')
        ->assertJsonPath('shipTo.city', 'London')
        ->assertJsonPath('lines.0.sku', 'WIDGET-1')
        ->assertJsonPath('total', 22.25);

    // Narrowed rather than merely asserted: the id is threaded into four URLs below, and a null there would
    // otherwise surface as a confusing 404 instead of "the create response carried no id".
    $id = $created->json('id');
    if (! is_int($id)) {
        throw new RuntimeException('the created order came back without an integer id.');
    }

    // READ — the id came back through a #[PathVariable] and was COERCED from the URL string to an int.
    $this->getJson('/orders/'.$id)
        ->assertOk()
        ->assertJsonPath('id', $id)
        ->assertJsonPath('email', 'ada@example.com');

    // LIST — paged, with both #[QueryParam]s bound and coerced from their query-string form.
    $this->getJson('/orders?page=1&size=5')
        ->assertOk()
        ->assertJsonPath('page', 1)
        ->assertJsonPath('size', 5)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.id', $id);

    // REPLACE — the same DTO as store, so the same validation applies to both.
    $this->putJson('/orders/'.$id, SkeletonApp::orderBody(['customer' => 'Grace Hopper']))
        ->assertOk()
        ->assertJsonPath('id', $id)
        ->assertJsonPath('customer', 'Grace Hopper');

    // DELETE — 204 with an EMPTY body, from a `void` action and a status declared on the mapping.
    $this->deleteJson('/orders/'.$id)->assertNoContent();

    $this->getJson('/orders/'.$id)->assertStatus(404);
});

it('omits both paging parameters without a 500 — the #[QueryParam] default trap', function (): void {
    /** @var SkeletonExampleTestCase $this */
    // A #[QueryParam]'s fallback is compiled from the ATTRIBUTE, never from the PHP default value. Without
    // `default:` on the attribute an absent `?page` binds null and dies against the `int` in the signature,
    // which is a 500 for a request that merely omitted an optional parameter.
    $this->getJson('/orders')
        ->assertOk()
        ->assertJsonPath('page', 1)
        ->assertJsonPath('size', 20);
});

it('clamps an oversized ?size to the controller\'s own maximum', function (): void {
    /** @var SkeletonExampleTestCase $this */
    // MAX_PAGE_SIZE is the only thing standing between `?size=100000` and pushing the whole store through
    // one response. The echoed `size` is the CLAMPED value the service was actually called with, so this
    // fails the moment the clamp is dropped from the action.
    $this->getJson('/orders?size=100000')
        ->assertOk()
        ->assertJsonPath('size', 100);

    // The lower bound too: a nonsensical page or size is floored at 1 rather than reaching array_slice as a
    // negative offset.
    $this->getJson('/orders?page=0&size=0')
        ->assertOk()
        ->assertJsonPath('page', 1)
        ->assertJsonPath('size', 1);
});

it('pages past the first page rather than repeating it', function (): void {
    /** @var SkeletonExampleTestCase $this */
    // Every other case here creates ONE order, which cannot tell a real 1-based offset from a repository
    // that ignores $page entirely and always returns the head of the list. Three orders and a second page
    // can.
    $ids = [];
    foreach (['Ada Lovelace', 'Grace Hopper', 'Alan Turing'] as $customer) {
        $created = $this->postJson('/orders', SkeletonApp::orderBody(['customer' => $customer]));
        $created->assertStatus(201);
        $id = $created->json('id');
        if (! is_int($id)) {
            throw new RuntimeException('the created order came back without an integer id.');
        }
        $ids[] = $id;
    }

    $this->getJson('/orders?page=1&size=2')
        ->assertOk()
        ->assertJsonPath('total', 3)
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('items.0.id', $ids[0])
        ->assertJsonPath('items.1.id', $ids[1]);

    // The second page holds the REMAINDER — one row, the third id — not the first two over again.
    $this->getJson('/orders?page=2&size=2')
        ->assertOk()
        ->assertJsonPath('total', 3)
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.id', $ids[2]);

    // Past the end is an empty page, not a wrapped one.
    $this->getJson('/orders?page=9&size=2')
        ->assertOk()
        ->assertJsonCount(0, 'items');
});

it('rejects an invalid nested field as a 422 naming the dotted path the client sent', function (): void {
    /** @var SkeletonExampleTestCase $this */
    $body = SkeletonApp::orderBody([
        'shipTo' => [
            'street' => '12 Analytical Way',
            'city' => 'London',
            'postcode' => '',      // fails #[NotBlank]
            'country' => 'XX',     // not an ISO 3166-1 alpha-2 country
        ],
    ]);

    // The #[Valid] cascade compiles AddressPayload's rules under dot keys, so the field errors name
    // `shipTo.country` — the exact JSON path the client posted, not a flattened alias.
    $response = $this->postJson('/orders', $body);
    $response->assertStatus(422);

    $fields = array_column((array) $response->json('errors'), 'field');
    expect($fields)->toContain('shipTo.country')->toContain('shipTo.postcode');
});

it('rejects a missing required body field as a 422 rather than a bind failure', function (): void {
    /** @var SkeletonExampleTestCase $this */
    $body = SkeletonApp::orderBody();
    unset($body['lines']);

    // #[NotEmpty] emits Laravel's implicit `required`; a rule OBJECT such as #[Size] is skipped for an
    // absent key, so without the implicit constraint this body would sail past validation and fail in the
    // DTO constructor as a 400 "could not bind" instead.
    $response = $this->postJson('/orders', $body);
    $response->assertStatus(422);

    expect(array_column((array) $response->json('errors'), 'field'))->toContain('lines');
});

it('answers an unknown order with an RFC-7807 problem document, not a bare 404', function (): void {
    /** @var SkeletonExampleTestCase $this */
    // OrderService throws ResourceNotFoundException; firefly/web renders the whole FireflyException taxonomy
    // as problem+json at the exception's own status. The controller contains no error handling at all.
    $this->getJson('/orders/424242')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'ORDER_NOT_FOUND')
        ->assertJsonPath('detail', 'Order 424242 does not exist.');
});

it('still serves the minimal greeting slice the tutorial is built on', function (): void {
    /** @var SkeletonExampleTestCase $this */
    // #[ConfigProperties('greeting')] bound from configuration (the 'Hello' default), autowired into a
    // #[Service], returned through a one-line #[RestController]. The README and the tutorial quote these
    // three files verbatim, so they are load-bearing documentation as well as a sample.
    $this->getJson('/greetings/Ada')
        ->assertOk()
        ->assertExactJson(['message' => 'Hello, Ada!']);
});

it('keeps every sample route on a path a developer would actually ship', function (): void {
    // The generator used to emit `#[GetMapping('/OrderController')]` — the class name as a URL. Nothing in
    // the shipped example may carry a path segment with a capital letter or the word "Controller" in it.
    $routes = RouteManifest::load((string) SkeletonExampleTestCase::$cacheDir.'/'.FireflyCachePaths::ROUTES)->all();

    $paths = array_map(static fn (RouteDescriptor $r): string => $r->path, $routes);
    expect($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        // `{id}` placeholders are the one legal source of a non-lowercase-friendly segment, and they are
        // lowercase here anyway; the assertion is on the literal segments.
        expect($path)->not->toMatch('/Controller/')
            ->and(preg_match('/[A-Z]/', $path))->toBe(0, "route path [{$path}] contains an upper-case segment");
    }
});
