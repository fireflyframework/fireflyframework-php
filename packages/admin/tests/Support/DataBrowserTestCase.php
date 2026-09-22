<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

use Firefly\Actuator\Introspection\BeansCatalog;
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

        $this->app()->instance(BeansCatalog::class, new BeansCatalog([[
            'class' => AdminRecordRepository::class,
            'stereotype' => 'repository',
            'scope' => 'Singleton',
            'name' => null,
            'interfaces' => array_values(class_implements(AdminRecordRepository::class) ?: []),
            'beans' => [],
        ]]));
    }
}
