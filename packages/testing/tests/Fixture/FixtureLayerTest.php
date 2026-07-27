<?php

declare(strict_types=1);

use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Firefly\Testing\Fixture\AggregateSeeder;
use Firefly\Testing\Fixture\FixtureRegistry;

final class Widget
{
    public function __construct(public string $name, public int $qty = 1) {}
}

it('registers, makes with overrides, and loads named fixtures', function () {
    $registry = (new FixtureRegistry)
        ->register('widget', function (array $overrides): Widget {
            $name = $overrides['name'] ?? 'default';
            $qty = $overrides['qty'] ?? 1;

            return new Widget(
                is_string($name) ? $name : 'default',
                is_int($qty) ? $qty : 1,
            );
        });

    /** @var Widget $default */
    $default = $registry->make('widget');
    /** @var Widget $bolt */
    $bolt = $registry->make('widget', ['name' => 'bolt', 'qty' => 5]);

    expect($registry->has('widget'))->toBeTrue()
        ->and($default->name)->toBe('default')
        ->and($bolt->qty)->toBe(5)
        ->and($registry->load('widget', 'widget'))->toHaveCount(2);
});

it('publishes an aggregate\'s domain events through the publisher port', function () {
    $publisher = new RecordingApplicationEventPublisher;
    (new AggregateSeeder)->publishEvents($publisher, new Widget('a'), new Widget('b'));

    expect($publisher->events)->toHaveCount(2);
});
