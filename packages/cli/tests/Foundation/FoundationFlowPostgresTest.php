<?php

declare(strict_types=1);

use Firefly\Cli\Tests\Fixtures\Foundation\FoundationWriter;
use Firefly\Cli\Tests\Fixtures\Foundation\WidgetRecord;
use Firefly\Cli\Tests\Fixtures\Foundation\WidgetRepository;
use Firefly\Cli\Tests\Support\FoundationFlowPostgresTestCase;

/**
 * @group integration — EXCLUDED from the default gate (phpunit.xml.dist excludes the `integration` group) and
 * RequiresDocker-skipped when Docker (and no FIREFLY_PG_DSN) is available. Proves the persistence slice — the
 * EloquentRepository derived query + the #[Transactional] commit — behaves identically on the CACHED path against
 * REAL Postgres as it does over sqlite.
 */
uses(FoundationFlowPostgresTestCase::class);

it('runs the persistence slice against real Postgres on the cached path', function () {
    /** @var FoundationFlowPostgresTestCase $this */
    WidgetRecord::query()->create(['status' => 'open', 'amount' => 10]);
    WidgetRecord::query()->create(['status' => 'closed', 'amount' => 20]);

    // (a) the EloquentRepository derived query returns the seeded row.
    /** @var WidgetRepository $repo */
    $repo = $this->fireflyContext()->get(WidgetRepository::class);
    expect($repo->findByStatus('open'))->toHaveCount(1);

    // (b) the #[Transactional] write commits via the generated proxy subclass.
    /** @var FoundationWriter $writer */
    $writer = $this->fireflyContext()->get(FoundationWriter::class);
    expect($writer::class)->toBe(FoundationWriter::class.'__FireflyTransactionalProxy')
        ->and($writer->store('committed'))->toBeGreaterThan(0)
        ->and(WidgetRecord::query()->where('status', 'committed')->exists())->toBeTrue();
})->group('integration');
