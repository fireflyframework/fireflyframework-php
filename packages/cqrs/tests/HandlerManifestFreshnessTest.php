<?php

declare(strict_types=1);

use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Scanner\HandlerScanner;

/**
 * DRIFT-GUARD: the committed packages/cqrs/cache/firefly-cqrs-handlers.php (which production LOADS) must stay in
 * lockstep with a fresh scan of packages/cqrs/src — which contains NO handlers, so it must stay EMPTY. Regenerate to
 * a temp path with the REAL scanner+compiler and compare LOADED content (handlers + destinations), format-independent.
 */
it('ships an empty handler manifest that is fresh against cqrs/src (no handlers live in the package)', function () {
    $src = ['Firefly\\Cqrs\\' => dirname(__DIR__).'/src'];
    $committed = dirname(__DIR__).'/cache/firefly-cqrs-handlers.php';

    $result = (new HandlerScanner)->scan($src);
    $committedManifest = HandlerManifest::load($committed);

    expect($committedManifest->handlers())->toBe($result['handlers']) // both []
        ->and($committedManifest->destinations())->toBe($result['destinations']); // both []
});
