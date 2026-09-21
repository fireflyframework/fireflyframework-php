<?php

declare(strict_types=1);

use Firefly\Cli\Cache\FireflyCachePaths;
use Firefly\Cli\Tests\Support\SkeletonApp;
use Firefly\Cli\Tests\Support\SkeletonExampleTestCase;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Support\Facades\DB;

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

    // Worded by the constraint and naming it — never `The ship to.postcode field is required.`
    expect((array) $response->json('errors'))
        ->toContain(['field' => 'shipTo.postcode', 'message' => 'must not be blank', 'constraint' => 'NotBlank', 'rejectedValue' => ''])
        ->toContain(['field' => 'shipTo.country', 'message' => 'must be a valid ISO 3166-1 alpha-2 country code', 'constraint' => 'CountryCode', 'rejectedValue' => 'XX']);
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

    expect((array) $response->json('errors'))->toBe([
        ['field' => 'lines', 'message' => 'must not be empty', 'constraint' => 'NotEmpty'],
    ]);
});

it('rejects a bad SKU on the second line as a 422 naming lines[1].sku with the constraint\'s own sentence', function (): void {
    /** @var SkeletonExampleTestCase $this */
    $body = SkeletonApp::orderBody([
        'lines' => [
            ['sku' => 'WIDGET-1', 'quantity' => 2, 'unitPrice' => 9.5],
            ['sku' => 'bad sku!', 'quantity' => 0, 'unitPrice' => 3.25],
        ],
    ]);

    // Before this release the cascade stopped at the list: each element was hydrated, OrderLinePayload's own
    // constraints never ran, and this exact body was a 201 — `bad sku!` is a perfectly good PHP `string` and
    // `0` a perfectly good `int`, so the constructor took them and the order was STORED with a SKU no
    // #[Pattern] would ever have let through. (An element the constructor did refuse — no quantity at all —
    // came back as a 400 UNBINDABLE_BODY naming `lines[1]`, the next case.) Now #[Valid] on `lines` cascades
    // into every element and the answer is the 422 a client can act on — the element's path as the client
    // wrote it, the constraint's sentence, the constraint's name.
    $response = $this->postJson('/orders', $body);
    $response->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    $errors = (array) $response->json('errors');

    expect($errors)->toHaveCount(2)
        ->toContain(['field' => 'lines[1].sku', 'message' => 'must match "^[A-Z0-9][A-Z0-9-]{2,31}$"', 'constraint' => 'Pattern', 'rejectedValue' => 'bad sku!'])
        ->toContain(['field' => 'lines[1].quantity', 'message' => 'must be greater than 0', 'constraint' => 'Positive', 'rejectedValue' => 0])
        ->and(array_column($errors, 'field'))->not->toContain('lines.1.sku')
        // No sentence is Laravel's `The lines.1.sku field format is invalid.` — the word never appears.
        ->and(array_column($errors, 'message'))->each->not->toContain('field');
});

it('rejects a line with no quantity as a 422 naming the element, not as a bind failure', function (): void {
    /** @var SkeletonExampleTestCase $this */
    $body = SkeletonApp::orderBody(['lines' => [['sku' => 'WIDGET-1', 'unitPrice' => 9.5]]]);

    // OrderLinePayload's `int $quantity` carries #[NotNull] for the reason OrderRequest already gives: a
    // non-implicit rule is skipped for an ABSENT key, so without it the line would pass validation and fail
    // in its own constructor as a 400.
    $response = $this->postJson('/orders', $body);
    $response->assertStatus(422);

    expect((array) $response->json('errors'))->toBe([
        ['field' => 'lines[0].quantity', 'message' => 'must not be null', 'constraint' => 'NotNull'],
    ]);
});

it('answers an unknown order with an RFC-7807 problem document, not a bare 404', function (): void {
    /** @var SkeletonExampleTestCase $this */
    // OrderService throws ResourceNotFoundException; firefly/web renders the whole FireflyException taxonomy
    // as problem+json at the exception's own status. The controller contains no error handling at all.
    $this->getJson('/orders/424242')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'ORDER_NOT_FOUND')
        ->assertJsonPath('detail', 'That order does not exist.');
});

it('answers a malformed order id with the same 404 as a missing one, before the service is asked', function (): void {
    /** @var SkeletonExampleTestCase $this */
    // `/orders/abc` used to be a 400 TYPE_CONVERSION_ERROR ("Could not convert id to int.") — the framework's
    // sentence, naming a parameter — and on a uuid-keyed resource it was a 500 from the database. The sample
    // declares the id's shape on the attribute with the resource's own 404 code, so the two answers read alike
    // and the wire cannot tell "no such order" from "not even an order id".
    $this->getJson('/orders/abc')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'ORDER_NOT_FOUND')
        ->assertJsonPath('detail', 'That order does not exist.')
        ->assertHeader('X-Correlation-Id');
});

it('answers a wrong verb on the sample resource in the product\'s words, with Allow', function (): void {
    /** @var SkeletonExampleTestCase $this */
    $response = $this->patchJson('/orders/1', []);

    $response->assertStatus(405)
        ->assertJsonPath('title', 'Method Not Allowed')
        ->assertJsonPath('code', 'METHOD_NOT_ALLOWED')
        ->assertJsonPath('detail', 'This address only accepts GET, PUT or DELETE.')
        ->assertJsonPath('allowed', ['GET', 'PUT', 'DELETE'])
        ->assertHeader('Allow');
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

it('writes an order and its lines into two tables, and cascades the delete', function () {
    /** @var SkeletonExampleTestCase $this */
    $id = $this->postJson('/orders', SkeletonApp::orderBody())->json('id');
    if (! is_int($id)) {
        throw new RuntimeException('the created order came back without an integer id.');
    }

    // The shipped sample has two tables because a LINE is an entity and an ADDRESS is a value: the address
    // is embedded as a json column on the order, the lines are rows with a foreign key. That split is what
    // gives the admin dashboard a relation to walk and what makes "how many WIDGET-1 did we sell" a query
    // rather than a JSON scan — and it is only correct if both writes actually happen.
    expect(DB::table('orders')->where('id', $id)->count())->toBe(1)
        ->and(DB::table('order_lines')->where('order_id', $id)->count())->toBe(2)
        ->and(DB::table('order_lines')->where('order_id', $id)->orderBy('id')->value('sku'))->toBe('WIDGET-1');

    $this->deleteJson('/orders/'.$id)->assertNoContent();

    // A cancelled order that left its lines behind would leave rows nothing can reach and every
    // sum(unit_price) wrong.
    expect(DB::table('order_lines')->where('order_id', $id)->count())->toBe(0);
});

it('replaces an order\'s lines wholesale rather than merging them', function () {
    /** @var SkeletonExampleTestCase $this */
    $id = $this->postJson('/orders', SkeletonApp::orderBody())->json('id');
    if (! is_int($id)) {
        throw new RuntimeException('the created order came back without an integer id.');
    }

    // A PUT says nothing about which line is which, so matching the incoming lines to the stored ones would
    // invent an identity the client never sent.
    $this->putJson('/orders/'.$id, SkeletonApp::orderBody([
        'lines' => [['sku' => 'BOLT-9', 'quantity' => 3, 'unitPrice' => 2.0]],
    ]))->assertOk()->assertJsonCount(1, 'lines');

    expect(DB::table('order_lines')->where('order_id', $id)->count())->toBe(1)
        ->and(DB::table('order_lines')->where('sku', 'WIDGET-1')->count())->toBe(0)
        // The total is recomputed from the new lines, never carried over from the old ones.
        // The driver hands a decimal back as a string, so the total is read as a scalar and cast once
        // rather than compared against whichever spelling this connection happens to return.
        ->and(scalarTotal($id))->toBe(6.0);
});

it('compiles a #[Transactional] proxy for the sample service', function () {
    // Placing an order is two statements across two tables, so the sample annotates its writes — and the
    // annotation is only real if `firefly:cache` actually emitted a proxy for it. A report of zero proxies
    // here would mean every write in the shipped example runs unwrapped while the docblock says otherwise.
    $report = SkeletonExampleTestCase::$report;
    if ($report === null) {
        throw new RuntimeException('the skeleton compile produced no report.');
    }

    expect($report->proxyCount)->toBeGreaterThan(0);
});

/** The stored total of one order, as a float whatever spelling the driver returned it in. */
function scalarTotal(int $id): float
{
    $value = DB::table('orders')->where('id', $id)->value('total');

    return is_scalar($value) ? (float) $value : 0.0;
}
