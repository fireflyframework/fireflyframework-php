<?php

declare(strict_types=1);

use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Listeners\NoteAudit;
use Firefly\Data\Tests\Fixtures\Listeners\NoteIndexed;
use Firefly\Data\Tests\Fixtures\Listeners\NoteSaved;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Data\Transaction\TransactionalManifestCompiler;

it('compiles every #[TransactionalEventListener] with its inferred event, phase, fallback and order', function () {
    $manifest = (new TransactionalScanner)->scan(['Firefly\\Data\\Tests\\Fixtures\\Listeners\\' => dirname(__DIR__).'/Fixtures/Listeners']);

    // Sorted by [order, class, method] — so the two NoteIndexed listeners slot in alphabetically among order 0.
    expect($manifest->listeners())->toBe([
        ['class' => NoteAudit::class, 'method' => 'beforeCommit', 'event' => NoteSaved::class, 'phase' => 'BEFORE_COMMIT', 'fallbackExecution' => false, 'order' => -10],
        ['class' => NoteAudit::class, 'method' => 'afterCommit', 'event' => NoteSaved::class, 'phase' => 'AFTER_COMMIT', 'fallbackExecution' => false, 'order' => 0],
        ['class' => NoteAudit::class, 'method' => 'afterCommitIndexed', 'event' => NoteIndexed::class, 'phase' => 'AFTER_COMMIT', 'fallbackExecution' => false, 'order' => 0],
        ['class' => NoteAudit::class, 'method' => 'afterCompletion', 'event' => NoteSaved::class, 'phase' => 'AFTER_COMPLETION', 'fallbackExecution' => false, 'order' => 0],
        ['class' => NoteAudit::class, 'method' => 'afterRollback', 'event' => NoteSaved::class, 'phase' => 'AFTER_ROLLBACK', 'fallbackExecution' => false, 'order' => 0],
        ['class' => NoteAudit::class, 'method' => 'beforeCommitIndexed', 'event' => NoteIndexed::class, 'phase' => 'BEFORE_COMMIT', 'fallbackExecution' => false, 'order' => 0],
        ['class' => NoteAudit::class, 'method' => 'alwaysAfterCommit', 'event' => NoteSaved::class, 'phase' => 'AFTER_COMMIT', 'fallbackExecution' => true, 'order' => 10],
    ]);

    $path = sys_get_temp_dir().'/firefly-listeners-manifest-'.bin2hex(random_bytes(6)).'.php';
    try {
        (new TransactionalManifestCompiler)->write($manifest, $path);
        expect(TransactionalManifest::load($path)->listeners())->toBe($manifest->listeners());
    } finally {
        @unlink($path);
    }
});
