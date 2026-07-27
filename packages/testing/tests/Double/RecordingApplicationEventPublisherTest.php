<?php

declare(strict_types=1);

use Firefly\Testing\Double\RecordingApplicationEventPublisher;

final class SamplePublishedEvent
{
    public function __construct(public int $id) {}
}

it('records published application events in order and filters by type', function () {
    $publisher = new RecordingApplicationEventPublisher;
    $publisher->publish(new SamplePublishedEvent(1));
    $publisher->publish(new stdClass);
    $publisher->publish(new SamplePublishedEvent(2));

    expect($publisher->events)->toHaveCount(3)
        ->and($publisher->ofType(SamplePublishedEvent::class))->toHaveCount(2)
        ->and($publisher->ofType(SamplePublishedEvent::class)[0])->toBeInstanceOf(SamplePublishedEvent::class);
});
