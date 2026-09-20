<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Listeners;

use Firefly\Container\Attributes\Component;
use Firefly\Data\Transaction\Attributes\TransactionalEventListener;
use Firefly\Data\Transaction\TransactionPhase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One listener per phase, plus one with fallbackExecution. Each appends `<phase>:<title>:<rows>:<level>` to the
 * static log, where <rows> is what `notes` holds for that title AT THAT MOMENT and <level> the transaction
 * level — the proof of the phase: BEFORE_COMMIT sees the row inside the open transaction (1:1), AFTER_COMMIT
 * sees it committed (1:0), AFTER_ROLLBACK sees it gone (0:0). A title of `veto` makes the BEFORE_COMMIT
 * listener throw, which must abort the commit.
 */
#[Component]
final class NoteAudit
{
    /** @var list<string> */
    public static array $log = [];

    public static function reset(): void
    {
        self::$log = [];
    }

    #[TransactionalEventListener(phase: TransactionPhase::BEFORE_COMMIT, order: -10)]
    public function beforeCommit(NoteSaved $event): void
    {
        self::record('before-commit', $event);

        if ($event->title === 'veto') {
            throw new RuntimeException('vetoed before commit');
        }
    }

    #[TransactionalEventListener]
    public function afterCommit(NoteSaved $event): void
    {
        self::record('after-commit', $event);
    }

    #[TransactionalEventListener(phase: TransactionPhase::AFTER_ROLLBACK)]
    public function afterRollback(NoteSaved $event): void
    {
        self::record('after-rollback', $event);
    }

    #[TransactionalEventListener(phase: TransactionPhase::AFTER_COMPLETION)]
    public function afterCompletion(NoteSaved $event): void
    {
        self::record('after-completion', $event);
    }

    #[TransactionalEventListener(fallbackExecution: true, order: 10)]
    public function alwaysAfterCommit(NoteSaved $event): void
    {
        self::record('fallback-after-commit', $event);
    }

    private static function record(string $phase, NoteSaved $event): void
    {
        self::$log[] = sprintf('%s:%s:%d:%d', $phase, $event->title, DB::table('notes')->where('title', $event->title)->count(), DB::connection()->transactionLevel());
    }
}
