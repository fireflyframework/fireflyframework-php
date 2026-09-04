<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The sample REST resource, end to end.
 *
 * `app/Http/OrderController.php` is what `php artisan make:firefly-controller OrderController` generates,
 * filled in — so these cases double as the documentation for what the generator gives you: five routes on a
 * derived collection path, a validated request body with a nested DTO and a list of DTOs, declared 201/204
 * statuses, and an RFC-7807 404 that no line of controller code produces.
 *
 * The store is in memory (see App\Orders\OrderRepository for why a skeleton must not assume a migrated
 * database), and the repository is a singleton, so state persists across the requests WITHIN one test. Each
 * test creates whatever it needs rather than relying on another test's leftovers, because PHPUnit gives no
 * ordering guarantee and a fresh application is booted per test.
 */
final class OrderTest extends TestCase
{
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

        // A `void` action plus #[DeleteMapping(status: 204)] is how you say "no body".
        $this->deleteJson('/orders/'.$id)->assertNoContent();
        $this->getJson('/orders/'.$id)->assertStatus(404);
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
