<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

use Firefly\Cli\Tests\Support\SkeletonApp;
use RuntimeException;

/**
 * Three orders through the skeleton's own REST API — the same path SkeletonExampleTest drives — so the
 * data browser has rows and a browser-made edit can be asserted against the SQLite row in-process.
 */
trait SeedsOrders
{
    /** @return list<int> the created ids, in the order of the customers below */
    protected function seedOrders(): array
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
}
