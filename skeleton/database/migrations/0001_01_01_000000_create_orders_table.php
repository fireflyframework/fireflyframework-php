<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one table the sample resource needs.
 *
 * `composer create-project` runs `artisan migrate` for you (see the skeleton's post-create-project-cmd), so
 * `POST /orders` works against the bundled sqlite file the moment the installer finishes. Delete this file
 * and app/Orders if you do not want the sample.
 *
 * TWO TABLES, AND THE SPLIT IS THE LESSON. `ship_to` stays a json column because an address is a VALUE — it
 * has no identity of its own, nothing ever queries for one, and nothing else refers to it. Lines are
 * ENTITIES: they have their own ids, "how many WIDGET-1 did we sell" is a query across them, and a JSON
 * column would make that a scan. So the address is embedded and the lines get a table with a foreign key,
 * which is the same call you make in Spring between an @Embeddable and an @Entity.
 *
 * It is also what makes the relation real. App\Orders\OrderEntity declares `hasMany(OrderLineEntity)` and
 * the line declares `belongsTo(OrderEntity)`, so the admin dashboard's data browser can walk from an order
 * to its lines and back — a feature a single-table sample could not have demonstrated at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('customer');
            $table->string('email');
            $table->json('ship_to');
            // Derived from the lines by the domain, stored so the column can be sorted, summed and reported
            // on without decoding a join — the ordinary reason a derived value is also persisted. Nothing
            // accepts it from a client: OrderService writes what Order::total() computed.
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();

            $table->index('email');
        });

        Schema::create('order_lines', function (Blueprint $table): void {
            $table->id();
            // cascadeOnDelete so removing an order removes its lines in the DATABASE, not only in whichever
            // code path happened to remember. OrderService deletes them explicitly too, inside the same
            // transaction, because sqlite enforces foreign keys only when the pragma is on and an
            // application should not depend on a setting to keep its own invariants.
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('sku');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->timestamps();

            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
