<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

use Firefly\Actuator\Introspection\BeansCatalog;
use Firefly\Tests\Browser\Support\Fixtures\BrowserSubscriberRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The skeleton plus ONE Eloquent-backed resource with a unique column, so the data browser has a duplicate to
 * refuse: the `browser_subscribers` table with one row, and its repository appended to the actuator's
 * BeansCatalog — the boot-time snapshot DataResourceRegistry discovers resources from.
 *
 * WHY THE CATALOGUE AND NOT A SCAN PATH. The browser suite boots the skeleton on the CACHED path (compiled
 * manifests, no `firefly.scan.paths`), so a repository class under tests/ is invisible to the scanner by
 * design. The registry reads the catalogue lazily, when the first request assembles the DataBrowser, and the
 * admin package's own DataBrowserTestCase::exposeAdminRecords() swaps the instance before that request for
 * the same reason; this fixture APPENDS a row instead of replacing the catalogue, so the skeleton's Order
 * Entity and Order Line Entity stay listed beside the fixture's.
 *
 * Nothing here sets firefly.management.endpoint.health.db.enabled: the `db` row the health scenario asserts
 * is the data wave's default-on, proved as the default.
 */
abstract class DataSurfacesBrowserTestCase extends BrowserTestCase
{
    public const string EXISTING_EMAIL = 'ada@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->exposeSubscribers();
    }

    private function exposeSubscribers(): void
    {
        Schema::create('browser_subscribers', static function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
        });
        DB::table('browser_subscribers')->insert(['email' => self::EXISTING_EMAIL, 'name' => 'Ada Lovelace']);

        /** @var BeansCatalog $catalog */
        $catalog = $this->app()->make(BeansCatalog::class);
        $this->app()->instance(BeansCatalog::class, new BeansCatalog([
            ...$catalog->all(),
            [
                'class' => BrowserSubscriberRepository::class,
                'stereotype' => 'repository',
                'scope' => 'Singleton',
                'name' => null,
                'interfaces' => array_values(class_implements(BrowserSubscriberRepository::class) ?: []),
                'beans' => [],
            ],
        ]));
    }
}
