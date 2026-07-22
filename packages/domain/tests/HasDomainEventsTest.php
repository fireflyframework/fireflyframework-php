<?php

declare(strict_types=1);

use Firefly\Domain\DomainEvent;
use Firefly\Domain\HasDomainEvents;
use Firefly\Domain\RecordsDomainEvents;

final readonly class TraitRaised extends DomainEvent {}

/**
 * A plain fixture that mixes in HasDomainEvents (no AggregateRoot, no Eloquent Model) and satisfies the
 * RecordsDomainEvents drain contract — the active-record case (a Model use HasDomainEvents) is proven end-to-end
 * in the T17 capstone.
 */
final class SampleRecorder implements RecordsDomainEvents
{
    use HasDomainEvents;

    public function doSomething(): void
    {
        $this->raiseEvent(new TraitRaised);
    }
}

it('becomes a RecordsDomainEvents by using HasDomainEvents', function () {
    expect(new SampleRecorder)->toBeInstanceOf(RecordsDomainEvents::class);
});

it('buffers raised events and snapshots them without draining (trait)', function () {
    $rec = new SampleRecorder;
    $rec->doSomething();
    $rec->doSomething();

    expect($rec->pendingEvents())->toHaveCount(2)
        ->and($rec->pendingEvents())->toHaveCount(2); // snapshot: repeated reads do not drain
});

it('drains events on pull and leaves the buffer empty (trait)', function () {
    $rec = new SampleRecorder;
    $rec->doSomething();

    $pulled = $rec->pullEvents();

    expect($pulled)->toHaveCount(1)
        ->and($pulled[0])->toBeInstanceOf(TraitRaised::class)
        ->and($rec->pendingEvents())->toBe([]);
});

it('clears events without returning them (trait)', function () {
    $rec = new SampleRecorder;
    $rec->doSomething();
    $rec->clearEvents();

    expect($rec->pendingEvents())->toBe([]);
});
