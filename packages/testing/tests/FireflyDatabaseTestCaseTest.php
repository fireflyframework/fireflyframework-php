<?php

declare(strict_types=1);

use Firefly\Testing\Tests\Support\WidgetFireflyDatabaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// NOTE (brief-test fix): the brief's literal test used
// `uses(new class extends FireflyDatabaseTestCase { ... }::class)`. That instantiates the anonymous
// class immediately to read its ::class, which throws before Pest ever binds it —
// PHPUnit\Framework\TestCase::__construct() requires a `string $name` argument a bare `new` expression
// never supplies (same gotcha documented on FireflyTestCaseTest.php / ProbeFireflyTestCase). Using the
// named WidgetFireflyDatabaseTestCase Support class instead, consistent with every other
// *CapstoneTestCase in this monorepo.
uses(WidgetFireflyDatabaseTestCase::class);

it('binds an in-memory sqlite connection as default', function () {
    /** @var WidgetFireflyDatabaseTestCase $this */
    expect($this->app()->make('config')->get('database.default'))->toBe('testing');
});

it('lets a subclass create schema and read it back', function () {
    DB::table('widgets')->insert(['name' => 'gizmo']);

    expect(Schema::hasTable('widgets'))->toBeTrue()
        ->and(DB::table('widgets')->where('name', 'gizmo')->count())->toBe(1);
});

it('composes the sqlite override with the subclass\'s own configOverrides() entry', function () {
    /** @var WidgetFireflyDatabaseTestCase $this */
    expect($this->app()->make('config')->get('database.default'))->toBe('testing')
        ->and($this->app()->make('config')->get('firefly.widgets.enabled'))->toBeTrue();
});
