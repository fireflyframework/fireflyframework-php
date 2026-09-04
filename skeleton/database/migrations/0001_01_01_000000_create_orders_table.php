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
 * SHIPPING ADDRESS AND LINES ARE JSON COLUMNS. An order's lines are worth a table of their own the moment
 * anything queries across them — "how many WIDGET-1 did we sell" is a join, not a JSON scan. They are one
 * column here because the sample's job is to show the framework's repository layer, and a second table would
 * add a relation to explain without adding anything to that story. Firefly\Data\Repository\EloquentRepository
 * is an Eloquent repository, so `hasMany` works exactly as it always does when you are ready for it.
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
            $table->json('lines');
            // Derived from the lines by the domain, stored so the column can be sorted, summed and reported
            // on without decoding JSON — the ordinary reason a derived value is also persisted. Nothing
            // accepts it from a client: OrderService writes what Order::total() computed.
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
