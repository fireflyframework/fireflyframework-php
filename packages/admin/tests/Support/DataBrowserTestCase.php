<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

use Firefly\Actuator\Introspection\BeansCatalog;
use Firefly\Admin\Tests\Data\Fixtures\AdminLinkRepository;
use Firefly\Admin\Tests\Data\Fixtures\AdminRecordRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The dashboard with the data browser switched ON and writes allowed. */
abstract class DataBrowserTestCase extends AdminCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.admin.data.enabled' => $this->dataEnabled(),
            'firefly.admin.data.writable' => $this->dataWritable(),
        ];
    }

    protected function dataEnabled(): bool
    {
        return true;
    }

    protected function dataWritable(): bool
    {
        return true;
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);
    }

    /**
     * One Eloquent-backed resource the REAL dashboard can list, edit and create: the `admin_records` table the
     * unit fixture uses, one row in it, and a bean catalogue naming its repository — swapped in for the
     * actuator's boot-time snapshot BEFORE the first request, which is when the dashboard assembles its
     * DataBrowser and reads the catalogue.
     *
     * Public for the same reason the unit-level helpers are: Pest binds the closure's `$this` to a class
     * PHPStan cannot relate to this one, so a protected helper would read as an illegal call.
     */
    public function exposeAdminRecords(): void
    {
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

        DB::table('admin_records')->insert([
            'id' => 1, 'email' => 'ada@example.test', 'api_token' => null, 'recovery_phrase' => null,
            'amount' => 50, 'active' => 1, 'meta' => null, 'created_at' => '2026-01-01 10:00:00',
        ]);

        $this->exposeRepository(AdminRecordRepository::class);
    }

    /**
     * The same, for a resource with NOTHING TO SEARCH: `admin_links` is four integers, the shape of any
     * pivot table, so `DataSchema::searchable()` publishes no column and `DataQueryEngine` answers `[[], 0]`
     * to every term. It replaces the catalogue rather than adding to it, exactly as its sibling does — a
     * test wants one resource in the menu, not two.
     *
     * Public for the same reason the sibling is: Pest binds the closure's `$this` to a class PHPStan cannot
     * relate to this one.
     */
    public function exposeAdminLinks(): void
    {
        Schema::create('admin_links', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('record_id');
            $table->integer('entry_id');
            $table->integer('quantity');
        });

        DB::table('admin_links')->insert([
            ['id' => 1, 'record_id' => 1, 'entry_id' => 7, 'quantity' => 3],
            ['id' => 2, 'record_id' => 1, 'entry_id' => 8, 'quantity' => 5],
        ]);

        $this->exposeRepository(AdminLinkRepository::class);
    }

    /**
     * The actuator's boot-time bean snapshot, swapped for one naming a single repository — done BEFORE the
     * first request, which is when the dashboard assembles its DataBrowser and reads the catalogue.
     *
     * @param  class-string  $repository
     */
    protected function exposeRepository(string $repository): void
    {
        $this->app()->instance(BeansCatalog::class, new BeansCatalog([[
            'class' => $repository,
            'stereotype' => 'repository',
            'scope' => 'Singleton',
            'name' => null,
            'interfaces' => array_values(class_implements($repository) ?: []),
            'beans' => [],
        ]]));
    }
}
