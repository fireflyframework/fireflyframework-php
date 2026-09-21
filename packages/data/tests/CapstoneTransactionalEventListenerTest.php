<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Data\Tests\Fixtures\Listeners\NoteAudit;
use Firefly\Data\Tests\Fixtures\Listeners\NoteSaved;
use Firefly\Data\Tests\Fixtures\Listeners\NoteService;
use Firefly\Data\Tests\Support\ListenersCapstoneTestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

uses(ListenersCapstoneTestCase::class);

function noteService(Application $app): NoteService
{
    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    /** @var NoteService $service */
    $service = $context->get(NoteService::class);

    return $service;
}

it('runs BEFORE_COMMIT inside the transaction and the AFTER_* phases after the commit, in phase then #[Order] order', function () {
    /** @var ListenersCapstoneTestCase $this */
    $service = noteService($this->app());
    expect($service::class)->not->toBe(NoteService::class);

    $service->save('t');

    // <phase>:<title>:<rows for the title>:<transaction level> — see NoteAudit
    expect(NoteAudit::$log)->toBe([
        'before-commit:t:1:1',
        'after-commit:t:1:0',
        'after-completion:t:1:0',
        'fallback-after-commit:t:1:0',
    ]);
});

it('runs AFTER_ROLLBACK and AFTER_COMPLETION on the rollback path, never AFTER_COMMIT, and discards BEFORE_COMMIT', function () {
    /** @var ListenersCapstoneTestCase $this */
    $service = noteService($this->app());

    expect(fn () => $service->saveAndFail('r'))->toThrow(RuntimeException::class)
        ->and(DB::table('notes')->count())->toBe(0)
        ->and(NoteAudit::$log)->toBe([
            'after-completion:r:0:0',
            'after-rollback:r:0:0',
        ]);

    // The discarded BEFORE_COMMIT does not replay on the next commit.
    NoteAudit::reset();
    $service->save('t2');
    expect(array_filter(NoteAudit::$log, static fn (string $line): bool => str_starts_with($line, 'before-commit')))->toBe(['before-commit:t2:1:1']);
});

it('skips every listener when no transaction is active, except one with fallbackExecution', function () {
    /** @var ListenersCapstoneTestCase $this */
    $this->app()->make(ApplicationEventPublisher::class)->publish(new NoteSaved('free'));

    expect(NoteAudit::$log)->toBe(['fallback-after-commit:free:0:0']);
});

it('aborts the commit when a BEFORE_COMMIT listener throws: rolled back, AFTER_ROLLBACK fired, exception out', function () {
    /** @var ListenersCapstoneTestCase $this */
    $service = noteService($this->app());

    expect(fn () => $service->save('veto'))->toThrow(RuntimeException::class, 'vetoed before commit')
        ->and(DB::table('notes')->count())->toBe(0)
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(NoteAudit::$log)->toBe([
            'before-commit:veto:1:1',
            'after-completion:veto:0:0',
            'after-rollback:veto:0:0',
        ]);
});

it('runs the BEFORE_COMMIT of an event published BY a BEFORE_COMMIT listener inside the same commit, never the next one', function () {
    /** @var ListenersCapstoneTestCase $this */
    $service = noteService($this->app());

    $service->save('chain');

    // NoteIndexed is published while the BEFORE_COMMIT queue drains (level still 1): its BEFORE_COMMIT joins THIS
    // commit, its AFTER_COMMIT joins this transaction's after-commit list behind the ones queued during save().
    expect(NoteAudit::$log)->toBe([
        'before-commit:chain:1:1',
        'before-commit-indexed:chain:1:1',
        'after-commit:chain:1:0',
        'after-completion:chain:1:0',
        'fallback-after-commit:chain:1:0',
        'after-commit-indexed:chain:1:0',
    ]);

    // Nothing from the chain lies in wait for an unrelated transaction on the same connection.
    NoteAudit::reset();
    $service->save('t2');
    expect(NoteAudit::$log)->toBe([
        'before-commit:t2:1:1',
        'after-commit:t2:1:0',
        'after-completion:t2:1:0',
        'fallback-after-commit:t2:1:0',
    ]);
});

it('gives an event published inside a NESTED savepoint that rolls back AFTER_ROLLBACK only, while the outer commit still drains its own BEFORE_COMMIT', function () {
    /** @var ListenersCapstoneTestCase $this */
    $service = noteService($this->app());

    $service->saveWithNestedFailure('outer', 'inner');

    // The inner AFTER_* run as Laravel unwinds to level 1 (rows for `inner` already 0); the inner BEFORE_COMMIT
    // and AFTER_COMMIT went with the savepoint, so the outer commit drains only the BEFORE_COMMIT queued at level 1.
    expect(DB::table('notes')->pluck('title')->all())->toBe(['outer'])
        ->and(NoteAudit::$log)->toBe([
            'after-completion:inner:0:1',
            'after-rollback:inner:0:1',
            'before-commit:outer:1:1',
            'after-commit:outer:1:0',
            'after-completion:outer:1:0',
            'fallback-after-commit:outer:1:0',
        ]);

    // Nothing from the savepoint lies in wait for the next transaction on the connection.
    NoteAudit::reset();
    $service->save('t2');
    expect(NoteAudit::$log)->toBe([
        'before-commit:t2:1:1',
        'after-commit:t2:1:0',
        'after-completion:t2:1:0',
        'fallback-after-commit:t2:1:0',
    ]);
});
