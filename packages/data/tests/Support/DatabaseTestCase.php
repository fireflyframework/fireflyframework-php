<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * A testbench base that wires the DB/Schema facades over an sqlite :memory: connection and creates a simple
 * `widgets` table. Used by the transaction engine (T4), the proxy characterization (T7) and the capstone (T9)
 * — anything that needs a REAL connection to prove begin/commit/rollBack.
 */
abstract class DatabaseTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
    }
}
