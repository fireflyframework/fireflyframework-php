<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

use Firefly\Cli\Tests\Support\SkeletonApp;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Three orders through the skeleton's own REST API — the same path SkeletonExampleTest drives — so the
 * data browser has rows and a browser-made edit can be asserted against the SQLite row in-process.
 *
 * And, below it, as many as a scenario asks for, written straight to the table. The two are separate
 * seeders rather than one parameterised seeder because they answer different questions and because
 * AdminDataBrowserTest reads the count of the first one off the page as `3 total`: a seeder that sometimes
 * left three rows and sometimes sixty-three would make that assertion depend on what else a scenario
 * happened to call.
 */
trait SeedsOrders
{
    /**
     * Public, not protected: the Pest closures that call it run with `$this` bound to the class Pest generates
     * per file, and PHPStan sees that as a call from outside the hierarchy — the same reason
     * FireflyTestCase::app() and the admin package's closure-facing helpers are public.
     *
     * @return list<int> the created ids, in the order of the customers below
     */
    public function seedOrders(): array
    {
        $ids = [];

        // Each order gets ONE distinctive SKU on top of the shared body, so a child list that was NOT
        // filtered by order_id is distinguishable from one that was.
        foreach ([
            'Ada Lovelace' => ['ada@example.com', 'ONLY-ADA'],
            'Grace Hopper' => ['grace@example.com', 'ONLY-GRACE'],
            'Margaret Hamilton' => ['margaret@example.com', 'ONLY-MARGARET'],
        ] as $customer => [$email, $sku]) {
            $response = $this->postJson('/orders', SkeletonApp::orderBody([
                'customer' => $customer,
                'email' => $email,
                'lines' => [['sku' => $sku, 'quantity' => 1, 'unitPrice' => 9.5]],
            ]));
            $response->assertStatus(201);

            $id = $response->json('id');
            if (! is_int($id)) {
                throw new RuntimeException("seeding {$customer} came back without an integer id.");
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Enough orders to page, seeded straight through the connection.
     *
     * NOT through the REST API like seedOrders(): sixty round trips through the HTTP kernel is a slow test
     * for no extra coverage — the three-order path already proves the API writes what the browser reads,
     * and what this one is for is SCALE. Every customer name is distinct and zero-padded so a sort over it
     * is a total order with an obvious expected sequence, and `total` ascends with the row number so a
     * numeric sort is visibly different from a lexical one.
     *
     * ONE TIMESTAMP FOR EVERY ROW, read once above the loop rather than per row. That is what makes
     * `created_at` an N-WAY TIE, and a tie is the only state in which a listing's tiebreak is observable at
     * all: order by it and the database is free to return the rows in any order it likes, so a pager
     * without a second ORDER BY shows one row on two pages and another on none. Calling `now()` inside the
     * loop would leave the rows a fraction of a second apart — or, worse, in the same second on a fast
     * machine and not on a slow one, which is a test that fails once a fortnight for a reason nobody finds.
     * `ship_to` cannot play that part however identical it is made: DataSchema::sortable() excludes json
     * columns on purpose, because ordering a serialized blob sorts its text.
     *
     * @return int the number of rows seeded
     */
    public function seedManyOrders(int $count = 60): int
    {
        $now = now();

        $rows = [];
        foreach (range(1, $count) as $n) {
            $rows[] = [
                'customer' => 'Customer '.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                'email' => 'customer'.$n.'@example.com',
                'total' => $n * 1.5,
                'ship_to' => json_encode(['street' => $n.' Long Street', 'city' => 'London', 'postcode' => 'E1 6AN', 'country' => 'GB']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('orders')->insert($rows);

        return $count;
    }
}
