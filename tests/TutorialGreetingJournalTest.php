<?php

declare(strict_types=1);

use App\Greetings\GreetingCounts;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\Tests\Support\TutorialEventSourcingTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(TutorialEventSourcingTestCase::class);

/**
 * docs/tutorial.md and docs/tutorial.es.md Step 14 print tests/Fixtures/Tutorial/app/Greetings/**, the journal
 * controller and the greeting_counts migration verbatim (source: markers, held line for line by DocsCodeIsRealTest),
 * so what the reader types is what this test boots — and the transcripts the step prints are these requests and
 * these two commands.
 */
it('records each greeting as an event and projects the count into the read model', function () {
    /** @var TutorialEventSourcingTestCase $this */
    $this->postJson('/journal/Ada')->assertStatus(201)->assertExactJson(['name' => 'Ada', 'times' => 1]);
    $this->postJson('/journal/Ada')->assertStatus(201)->assertExactJson(['name' => 'Ada', 'times' => 2]);

    // The events are stored; the read model has not been told yet.
    $this->getJson('/journal/Ada')->assertStatus(404)->assertHeader('Content-Type', 'application/problem+json');

    expect(Artisan::call('firefly:eventsourcing:projections:run'))->toBe(0)
        ->and(Artisan::output())->toContain('greeting.counts: caught-up, 2 event(s) handled, at position 2')
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', 'Ada')->orderBy('aggregate_version')->pluck('event_type')->all())
        ->toBe(['greeting.said', 'greeting.said']);

    $this->getJson('/journal/Ada')->assertOk()->assertExactJson(['name' => 'Ada', 'times' => 2, 'lastMessage' => 'Hello, Ada!']);
});

it('rebuilds the read model from the events alone', function () {
    /** @var TutorialEventSourcingTestCase $this */
    $this->postJson('/journal/Grace')->assertStatus(201);
    Artisan::call('firefly:eventsourcing:projections:run');
    DB::table(GreetingCounts::TABLE)->delete();

    expect(Artisan::call('firefly:eventsourcing:projections:rebuild', ['projection' => 'greeting.counts']))->toBe(0)
        ->and(Artisan::output())->toContain('greeting.counts rebuilt: 1 event(s) handled, at position 1');

    $this->getJson('/journal/Grace')->assertOk()->assertJsonPath('times', 1);
});
