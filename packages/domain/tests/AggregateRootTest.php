<?php

declare(strict_types=1);

use Firefly\Domain\AggregateRoot;
use Firefly\Domain\DomainEvent;
use Firefly\Domain\RecordsDomainEvents;

final readonly class SampleRaised extends DomainEvent {}

final class SampleAggregate extends AggregateRoot
{
    public function doSomething(): void
    {
        $this->raiseEvent(new SampleRaised);
    }
}

it('satisfies the RecordsDomainEvents drain contract', function () {
    expect(new SampleAggregate(1))->toBeInstanceOf(RecordsDomainEvents::class);
});

it('buffers raised events and snapshots them without draining', function () {
    $agg = new SampleAggregate(1);
    $agg->doSomething();
    $agg->doSomething();

    expect($agg->pendingEvents())->toHaveCount(2)
        ->and($agg->pendingEvents())->toHaveCount(2); // snapshot: repeated reads do not drain
});

it('drains events on pull and leaves the buffer empty', function () {
    $agg = new SampleAggregate(1);
    $agg->doSomething();

    $pulled = $agg->pullEvents();

    expect($pulled)->toHaveCount(1)
        ->and($pulled[0])->toBeInstanceOf(SampleRaised::class)
        ->and($agg->pendingEvents())->toBe([]);
});

it('clears events without returning them', function () {
    $agg = new SampleAggregate(1);
    $agg->doSomething();
    $agg->clearEvents();

    expect($agg->pendingEvents())->toBe([]);
});
