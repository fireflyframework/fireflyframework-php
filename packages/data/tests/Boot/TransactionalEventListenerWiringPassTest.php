<?php

declare(strict_types=1);

use Firefly\Context\Boot\BootPhase;
use Firefly\Data\Boot\TransactionalEventListenerWiringPass;

it('registers after the context\'s own #[AsEventListener] sweep in the same phase', function () {
    $pass = new TransactionalEventListenerWiringPass;

    expect($pass->phase())->toBe(BootPhase::EventListeners)
        ->and($pass->order())->toBe(10);
});
