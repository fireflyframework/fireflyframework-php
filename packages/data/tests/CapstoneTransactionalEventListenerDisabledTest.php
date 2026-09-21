<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Data\Tests\Fixtures\Listeners\NoteAudit;
use Firefly\Data\Tests\Fixtures\Listeners\NoteService;
use Firefly\Data\Tests\Support\ListenersDisabledCapstoneTestCase;
use Illuminate\Support\Facades\DB;

uses(ListenersDisabledCapstoneTestCase::class);

it('registers nothing when firefly.data.transactional-event-listeners.enabled is false', function () {
    /** @var ListenersDisabledCapstoneTestCase $this */
    /** @var NoteService $service */
    $service = $this->app()->make(ApplicationContext::class)->get(NoteService::class);

    $service->save('silent');

    expect(DB::table('notes')->count())->toBe(1)
        ->and(NoteAudit::$log)->toBe([]);
});
