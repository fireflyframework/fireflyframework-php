<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Support;

use Firefly\Actuator\Introspection\BeansCatalog;
use Firefly\Admin\Data\DataBrowser;
use Firefly\Admin\Data\DataColumn;
use Firefly\Admin\Data\DataRecord;
use Firefly\Admin\Data\DataResource;
use Firefly\Admin\Data\DataSchema;
use Firefly\Admin\Tests\Data\Fixtures\AdminEntryRepository;
use Firefly\Admin\Tests\Data\Fixtures\AdminRecordRepository;
use Firefly\Admin\Tests\Data\Fixtures\NotARepository;
use Firefly\Admin\Tests\Data\Fixtures\PlainNoteRepository;
use Firefly\Config\Config;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Everything the data-browser tests share: two REAL tables on the shared sqlite `:memory:` connection, two
 * REAL repositories over them, and a BeansCatalog built the way ActuatorRouteRegistrar builds the live one.
 *
 * The catalogue rows are assembled with `class_implements()` because that is literally what ComponentScanner
 * records at scan time — so the discovery these tests exercise is fed the same interface closure the running
 * application's catalogue carries, not a hand-picked list that happens to contain the interface discovery is
 * looking for.
 */
abstract class DataBrowserTestCase extends FireflyDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('admin_records', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('api_token')->nullable();
            $table->string('recovery_phrase')->nullable();
            $table->integer('amount');
            $table->boolean('active')->default(true);
            $table->text('meta')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        // The child half of the relation fixture: a foreign key back to admin_records, so a hasMany and a
        // belongsTo are both walkable.
        Schema::create('admin_entries', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('record_id');
            $table->string('note');
            $table->decimal('amount', 10, 2)->default(0);
        });

        Schema::create('admin_notes', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('pinned')->default(false);
        });

        // The second AdminRecord's table, for the slug-collision case. Its model declares NO casts, so its
        // column types are whatever the driver alone reports — which is what proves the schema path carries
        // its own weight rather than leaning on a cast for every non-string column.
        Schema::create('alt_admin_records', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('label');
            $table->boolean('archived')->default(false);
            $table->integer('rank')->default(0);
        });

        // A typed-value entity's table, and a table whose entity has no derivable identifier.
        Schema::create('widgets', function (Blueprint $table): void {
            $table->string('uuid')->primary();
            $table->string('name');
            $table->dateTime('occurred_at');
            $table->string('status');
            $table->text('tags');
            $table->string('price');
        });

        Schema::create('pairs', function (Blueprint $table): void {
            $table->string('left')->primary();
            $table->string('right');
        });
    }

    /**
     * Seed the Eloquent-backed table. The `o'brien` address is not decoration: it is the row a search for
     * `o'brien` has to find, which only happens if the term reached the driver as a BINDING.
     */
    protected function seedRecords(): void
    {
        $rows = [
            ['id' => 1, 'email' => 'ada@example.test', 'api_token' => 'sk_live_ada_secret', 'recovery_phrase' => 'correct horse battery', 'amount' => 50, 'active' => 1, 'meta' => '{"tier":"gold"}', 'created_at' => '2026-01-01 10:00:00'],
            ['id' => 2, 'email' => "o'brien@example.test", 'api_token' => null, 'recovery_phrase' => null, 'amount' => 150, 'active' => 1, 'meta' => null, 'created_at' => '2026-01-02 10:00:00'],
            ['id' => 3, 'email' => 'grace@example.test', 'api_token' => 'sk_live_grace_secret', 'recovery_phrase' => null, 'amount' => 250, 'active' => 0, 'meta' => '{"tier":"silver"}', 'created_at' => '2026-01-03 10:00:00'],
            ['id' => 4, 'email' => 'linus@example.test', 'api_token' => null, 'recovery_phrase' => null, 'amount' => 350, 'active' => 1, 'meta' => null, 'created_at' => '2026-01-04 10:00:00'],
            ['id' => 5, 'email' => 'edsger@example.test', 'api_token' => null, 'recovery_phrase' => null, 'amount' => 450, 'active' => 0, 'meta' => null, 'created_at' => '2026-01-05 10:00:00'],
        ];

        DB::table('admin_records')->insert($rows);
    }

    protected function seedEntries(): void
    {
        DB::table('admin_entries')->insert([
            ['id' => 1, 'record_id' => 1, 'note' => 'first for ada', 'amount' => 10.50],
            ['id' => 2, 'record_id' => 1, 'note' => 'second for ada', 'amount' => 20.25],
            ['id' => 3, 'record_id' => 3, 'note' => 'only for grace', 'amount' => 30.00],
        ]);
    }

    protected function seedWidgets(): void
    {
        DB::table('widgets')->insert([
            ['uuid' => 'w-1', 'name' => 'Sprocket', 'occurred_at' => '2026-02-01 09:30:00', 'status' => 'live', 'tags' => '["a","b"]', 'price' => '10.10 EUR'],
            ['uuid' => 'w-2', 'name' => 'Cog', 'occurred_at' => '2026-02-02 09:30:00', 'status' => 'draft', 'tags' => '[]', 'price' => '0.00 EUR'],
        ]);
    }

    protected function seedPairs(): void
    {
        DB::table('pairs')->insert([
            ['left' => 'alpha', 'right' => 'one'],
            ['left' => 'beta', 'right' => 'two'],
        ]);
    }

    protected function seedNotes(): void
    {
        DB::table('admin_notes')->insert([
            ['id' => 1, 'title' => 'Alpha', 'body' => 'first note', 'pinned' => 1],
            ['id' => 2, 'title' => 'Beta', 'body' => 'second note', 'pinned' => 0],
            ['id' => 3, 'title' => 'Gamma', 'body' => null, 'pinned' => 0],
        ]);
    }

    /**
     * A browser over the default fixture catalogue.
     *
     * @param  array<string, mixed>  $data  the `firefly.admin.data.*` subtree, dot-free
     */
    protected function browser(array $data = ['enabled' => true]): DataBrowser
    {
        return $this->browserOver(
            [AdminRecordRepository::class, PlainNoteRepository::class, NotARepository::class],
            $data,
        );
    }

    /**
     * A browser over the two halves of the relation fixture — a parent and its children.
     *
     * @param  array<string, mixed>  $data
     */
    protected function relatedBrowser(array $data = ['enabled' => true]): DataBrowser
    {
        return $this->browserOver([AdminRecordRepository::class, AdminEntryRepository::class], $data);
    }

    /**
     * A browser over an arbitrary catalogue — the seam the slug-collision test needs.
     *
     * @param  list<class-string>  $classes
     * @param  array<string, mixed>  $data
     */
    protected function browserOver(array $classes, array $data = ['enabled' => true]): DataBrowser
    {
        $this->app()->instance(BeansCatalog::class, new BeansCatalog(array_map($this->row(...), $classes)));

        return DataBrowser::forContainer(
            $this->app(),
            new Config(new Repository(['firefly' => ['admin' => ['data' => $data]]])),
        );
    }

    /**
     * A browser over a catalogue whose rows carry a HAND-WRITTEN interface list rather than one derived from
     * the classes themselves. That is not a contrivance: BeansCatalog is a compiled snapshot, and a snapshot
     * taken before a refactor can name a class that no longer implements what the row says it does.
     *
     * @param  array<class-string, list<class-string>>  $classes  bean class => the interfaces the row claims
     * @param  array<string, mixed>  $data
     */
    protected function browserOverStaleCatalog(array $classes, array $data = ['enabled' => true]): DataBrowser
    {
        $rows = [];
        foreach ($classes as $class => $interfaces) {
            $rows[] = [
                'class' => $class,
                'stereotype' => 'repository',
                'scope' => 'Singleton',
                'name' => null,
                'interfaces' => $interfaces,
                'beans' => [],
            ];
        }

        $this->app()->instance(BeansCatalog::class, new BeansCatalog($rows));

        return DataBrowser::forContainer(
            $this->app(),
            new Config(new Repository(['firefly' => ['admin' => ['data' => $data]]])),
        );
    }

    /** A browser with NO catalogue bound at all — the deployment where the actuator is switched off. */
    protected function browserWithoutCatalog(): DataBrowser
    {
        $this->app()->forgetInstance(BeansCatalog::class);

        return DataBrowser::forContainer(
            $this->app(),
            new Config(new Repository(['firefly' => ['admin' => ['data' => ['enabled' => true]]]])),
        );
    }

    /**
     * One catalogue row exactly as ActuatorRouteRegistrar publishes it.
     *
     * @param  class-string  $class
     * @return array{class: string, stereotype: string, scope: string, name: string|null, interfaces: list<class-string>, beans: list<string>}
     */
    private function row(string $class): array
    {
        return [
            'class' => $class,
            'stereotype' => str_ends_with($class, 'Repository') ? 'repository' : 'service',
            'scope' => 'Singleton',
            'name' => null,
            'interfaces' => array_values(class_implements($class) ?: []),
            'beans' => [],
        ];
    }

    /**
     * Non-null accessors for the four nullable lookups the browser exposes.
     *
     * Every one of them returns null for a real reason the tests elsewhere assert on (switched off, unknown
     * slug, no identifier), so the nullability is not an accident to be papered over. These exist so that a
     * test whose SUBJECT is the returned value reads as a chain of assertions rather than as a null check
     * followed by assertions — and so a lookup that unexpectedly returns null fails on the line that asked
     * for it, naming what it asked for, instead of on a "property on null" ten lines later.
     */
    protected function resourceOf(DataBrowser $browser, string $slug): DataResource
    {
        return $browser->resource($slug) ?? throw new RuntimeException("No browsable resource [{$slug}].");
    }

    protected function schemaOf(DataBrowser $browser, string $slug): DataSchema
    {
        return $browser->schema($slug) ?? throw new RuntimeException("No schema for resource [{$slug}].");
    }

    protected function columnOf(DataBrowser $browser, string $slug, string $column): DataColumn
    {
        return $this->schemaOf($browser, $slug)->column($column)
            ?? throw new RuntimeException("No column [{$column}] on resource [{$slug}].");
    }

    protected function recordOf(DataBrowser $browser, string $slug, int|string $id): DataRecord
    {
        return $browser->find($slug, $id) ?? throw new RuntimeException("No record [{$id}] of resource [{$slug}].");
    }

    /**
     * One cell of a listing row, proven to be a string. The rows are `array<string, mixed>` by construction —
     * a column's PHP type depends on the driver — so a test asserting on the text of a cell says so here.
     *
     * @param  array<string, mixed>  $row
     */
    protected function stringCell(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : throw new RuntimeException("Column [{$column}] is not a string.");
    }
}
