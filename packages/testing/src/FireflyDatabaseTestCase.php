<?php

declare(strict_types=1);

namespace Firefly\Testing;

use Closure;
use Firefly\Testing\Concerns\UsesSqliteMemory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FireflyTestCase + sqlite :memory:. Subclasses build schema via createSchema() (or in defineFireflyEnvironment). */
abstract class FireflyDatabaseTestCase extends FireflyTestCase
{
    use UsesSqliteMemory;

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);
        $this->defineSqliteMemory($app);
    }

    /** @param Closure(Blueprint): void $blueprint */
    protected function createSchema(string $table, Closure $blueprint): void
    {
        Schema::create($table, $blueprint);
    }
}
