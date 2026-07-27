<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A testbench base that wires the DB/Schema facades over an sqlite :memory: connection (inherited from
 * FireflyDatabaseTestCase) and creates a simple `widgets` table. Used by the transaction engine (T4), the proxy
 * characterization (T7) and the capstone (T9) — anything that needs a REAL connection to prove begin/commit/rollBack.
 */
abstract class DatabaseTestCase extends FireflyDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
    }
}
