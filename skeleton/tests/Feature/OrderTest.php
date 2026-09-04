<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Orders\OrderEntity;
use App\Orders\OrderRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The sample REST resource, end to end.
 *
 * `app/Http/OrderController.php` is what `php artisan make:firefly-controller OrderController` generates,
 * filled in — so these cases double as the documentation for what the generator gives you: five routes on a
 * derived collection path, a validated request body with a nested DTO and a list of DTOs, declared 201/204
 * statuses, and an RFC-7807 404 that no line of controller code produces.
 *
 * The store is the `orders` and `order_lines` tables, reached through App\Orders\OrderRepository and
 * OrderLineRepository — both EloquentRepositories with a model name and no method bodies. RefreshDatabase
 * migrates the in-memory sqlite configured in phpunit.xml and rolls each test back, so every case starts
 * empty and none depends on another's leftovers.
 *
 * TWO TABLES IS WHY THE WRITES ARE #[Transactional]. Placing an order is two statements and replacing one is
 * three; a crash between them would leave an order with half its lines and a `total` matching neither. The
 * cases below assert both tables after every write for that reason — a response that looked right while only
 * one table was written is exactly the failure the annotation exists to prevent.
 *
 * THE PERSISTENCE ASSERTIONS BELOW ARE NOT DECORATION. An earlier version of this sample kept orders in an
 * array on a singleton repository, and this suite passed: Laravel reuses one application across the requests
 * of a single test, so the array survived from the POST to the GET. Over real HTTP it does not — PHP shares
 * nothing between requests, so `POST /orders` returned an id and the next `GET /orders` reported an empty
 * store. A test that only ever asks the same process what it just remembered cannot tell the two apart,
 * which is why these cases check the DATABASE as well as the response.
 */
final class OrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return [
            'customer' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'shipTo' => [
                'street' => '12 Analytical Way',
                'city' => 'London',
                'postcode' => 'W1A 1AA',
                'country' => 'GB',
            ],
            'lines' => [
                ['sku' => 'WIDGET-1', 'quantity' => 2, 'unitPrice' => 9.5],
                ['sku' => 'GEAR-77', 'quantity' => 1, 'unitPrice' => 3.25],
            ],
            ...$overrides,
        ];
    }

    public function test_it_creates_an_order_with_a_nested_address_and_a_list_of_lines(): void
    {
        $response = $this->postJson('/orders', $this->body());

        // 201 is declared on #[PostMapping(status: 201)]; nothing in the controller builds a response.
        $response->assertStatus(201)
            ->assertJsonPath('customer', 'Ada Lovelace')
            // The nested payload was hydrated into an AddressPayload and mapped to the domain Address.
            ->assertJsonPath('shipTo.city', 'London')
            // Each element of `lines` became an OrderLinePayload — `total` is computed from the OrderLine
            // objects built from them, so a raw sub-array reaching the domain would show up right here.
            ->assertJsonPath('lines.0.sku', 'WIDGET-1')
            ->assertJsonPath('total', 22.25);

        $this->assertIsInt($response->json('id'));

        // The row, not the response. `total` is a decimal column written from Order::total(), and the two
        // json columns hold the nested payloads — read back here so a controller that answered correctly
        // while storing nothing could not pass.
        $this->assertDatabaseHas('orders', [
            'id' => $response->json('id'),
            'customer' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'total' => 22.25,
        ]);

        // The lines went to their own table with a foreign key, which is what makes them queryable and what
        // lets the admin dashboard walk from an order to them.
        $this->assertDatabaseHas('order_lines', ['order_id' => $response->json('id'), 'sku' => 'GEAR-77', 'quantity' => 1]);
        $this->assertDatabaseCount('order_lines', 2);
    }

    public function test_it_reads_lists_replaces_and_deletes_an_order(): void
    {
        $id = $this->postJson('/orders', $this->body())->json('id');

        // #[PathVariable] binds AND coerces: the URL segment is a string, the action takes an int.
        $this->getJson('/orders/'.$id)
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('email', 'ada@example.com');

        $this->getJson('/orders?page=1&size=5')
            ->assertOk()
            ->assertJsonPath('page', 1)
            ->assertJsonPath('size', 5)
            ->assertJsonPath('items.0.id', $id);

        // PUT is a full replacement and takes the SAME DTO as POST, so the same rules apply to both.
        $this->putJson('/orders/'.$id, $this->body(['customer' => 'Grace Hopper']))
            ->assertOk()
            ->assertJsonPath('customer', 'Grace Hopper');

        // Replacement keeps the identity: the same row, refilled, rather than a delete and re-insert.
        $this->assertDatabaseHas('orders', ['id' => $id, 'customer' => 'Grace Hopper']);
        $this->assertDatabaseCount('orders', 1);

        // A `void` action plus #[DeleteMapping(status: 204)] is how you say "no body".
        $this->deleteJson('/orders/'.$id)->assertNoContent();
        $this->getJson('/orders/'.$id)->assertStatus(404);
        $this->assertDatabaseMissing('orders', ['id' => $id]);

        // And the lines went with it. A cancelled order that left its lines behind would leave rows nothing
        // can reach and every `sum(unit_price)` wrong.
        $this->assertDatabaseCount('order_lines', 0);
    }

    /**
     * Replacing an order replaces its lines outright.
     *
     * A PUT says nothing about which line is which, so matching the incoming lines to the stored ones would
     * be inventing an identity the client never sent. Delete-and-reinsert is the honest reading, and it is
     * only safe because #[Transactional] holds the window open — on its own it is a moment in which the
     * order has no lines at all.
     */
    public function test_replacing_an_order_replaces_its_lines(): void
    {
        $id = $this->postJson('/orders', $this->body())->json('id');
        $this->assertDatabaseCount('order_lines', 2);

        $this->putJson('/orders/'.$id, $this->body(['lines' => [['sku' => 'BOLT-9', 'quantity' => 3, 'unitPrice' => 2.0]]]))
            ->assertOk()
            ->assertJsonPath('lines.0.sku', 'BOLT-9')
            ->assertJsonCount(1, 'lines')
            // The total is recomputed from the NEW lines, never carried over.
            // JSON has one number type, so an exact total encodes as `6` and a fractional one as `22.25`.
            ->assertJsonPath('total', 6);

        $this->assertDatabaseCount('order_lines', 1);
        $this->assertDatabaseHas('order_lines', ['order_id' => $id, 'sku' => 'BOLT-9', 'quantity' => 3]);
        $this->assertDatabaseMissing('order_lines', ['sku' => 'WIDGET-1']);
    }

    /**
     * The order left the process, and a reader that never saw the write can find it.
     *
     * This is the case the in-memory version could not have passed, and the reason it went unnoticed is that
     * it never had to: `postJson()` followed by `getJson()` reuses one application, so an array on a
     * singleton repository looked exactly like a database. Querying the connection directly — and reading
     * back through a repository instance built after the write, which shares no state with the one that
     * handled it — is what separates a store from a cache inside a single test process.
     */
    public function test_an_order_is_written_to_the_database_and_not_to_process_memory(): void
    {
        $id = $this->postJson('/orders', $this->body())->json('id');

        // The raw rows, read straight off the connection. An order is two tables, so this is also where the
        // second write is proved to have happened.
        $row = DB::table('orders')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame('ada@example.com', $row->email);
        $this->assertCount(2, DB::table('order_lines')->where('order_id', $id)->get());

        // `ship_to` stays a json column: an address is a VALUE with no identity, so it is embedded rather
        // than given a table of its own. That split — value embedded, entity related — is the sample's
        // whole point about modelling.
        $this->assertSame('London', ((array) json_decode((string) $row->ship_to, true))['city']);

        // A repository built now, by hand, with no connection to the one that served the POST.
        $found = (new OrderRepository)->findById($id);
        $this->assertInstanceOf(OrderEntity::class, $found);
        $this->assertSame('Ada Lovelace', $found->customer);

        // And the derived query, parsed from its own name, finds it by a column nothing indexed by hand.
        $this->assertCount(1, (new OrderRepository)->findByEmailOrderByIdDesc('ada@example.com'));
    }

    public function test_it_defaults_both_paging_parameters_when_the_query_string_omits_them(): void
    {
        // A #[QueryParam]'s fallback is compiled from the ATTRIBUTE, never from the PHP default value —
        // which is why the controller writes `#[QueryParam(default: 1)] int $page = 1`. Without the
        // attribute default an absent `?page` binds null and fails against the `int` in the signature.
        $this->getJson('/orders')
            ->assertOk()
            ->assertJsonPath('page', 1)
            ->assertJsonPath('size', 20);
    }

    public function test_it_clamps_an_oversized_page_size(): void
    {
        // MAX_PAGE_SIZE is what stops `?size=100000` pushing the whole store through one response. The
        // echoed `size` is the CLAMPED value the service was actually called with.
        $this->getJson('/orders?size=100000')
            ->assertOk()
            ->assertJsonPath('size', 100);

        // And the lower bound: a nonsensical page or size is floored at 1, never reaching the repository as
        // a negative offset.
        $this->getJson('/orders?page=0&size=0')
            ->assertOk()
            ->assertJsonPath('page', 1)
            ->assertJsonPath('size', 1);
    }

    public function test_it_pages_past_the_first_page(): void
    {
        // One order cannot tell a real 1-based offset from a repository that always returns the head of the
        // list, so this case creates three and asks for the second page.
        $ids = [];
        foreach (['Ada Lovelace', 'Grace Hopper', 'Alan Turing'] as $customer) {
            $ids[] = $this->postJson('/orders', $this->body(['customer' => $customer]))->json('id');
        }

        $this->getJson('/orders?page=1&size=2')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.id', $ids[0]);

        // The second page is the REMAINDER — one row, the third id — not the first two over again.
        $this->getJson('/orders?page=2&size=2')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $ids[2]);

        // Past the end is an empty page, not a wrapped one.
        $this->getJson('/orders?page=9&size=2')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_it_rejects_an_invalid_nested_field_with_a_422_naming_the_dotted_path(): void
    {
        $body = $this->body();
        $body['shipTo']['country'] = 'XX';

        $response = $this->postJson('/orders', $body);

        // #[Valid] on the nested AddressPayload makes the constraint scanner compile its rules under dot
        // keys, so the client is told `shipTo.country` — the exact path it sent.
        $response->assertStatus(422);
        $this->assertContains('shipTo.country', array_column((array) $response->json('errors'), 'field'));
    }

    public function test_it_rejects_a_missing_required_field_with_a_422(): void
    {
        $body = $this->body();
        unset($body['lines']);

        $response = $this->postJson('/orders', $body);

        $response->assertStatus(422);
        $this->assertContains('lines', array_column((array) $response->json('errors'), 'field'));
    }

    public function test_an_unknown_order_is_an_rfc_7807_problem_document(): void
    {
        // OrderService throws ResourceNotFoundException; firefly/web renders the whole FireflyException
        // taxonomy as problem+json at the exception's own status. The controller handles nothing.
        $this->getJson('/orders/424242')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'ORDER_NOT_FOUND');
    }
}
