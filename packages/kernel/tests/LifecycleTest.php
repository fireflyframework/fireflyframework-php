<?php

declare(strict_types=1);

use Firefly\Kernel\Lifecycle;

it('defines a start/stop contract implementable by adapters', function () {
    $adapter = new class implements Lifecycle
    {
        /** @var list<string> */
        public array $calls = [];

        public function start(): void
        {
            $this->calls[] = 'start';
        }

        public function stop(): void
        {
            $this->calls[] = 'stop';
        }
    };

    $adapter->start();
    $adapter->stop();

    expect($adapter)->toBeInstanceOf(Lifecycle::class)
        ->and($adapter->calls)->toBe(['start', 'stop']);
});
