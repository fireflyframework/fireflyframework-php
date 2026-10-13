<?php

declare(strict_types=1);

namespace Lumen\Application\Loan;

use Firefly\Container\Attributes\Component;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Projection\Projection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the loan book (`loan_book`) from the global event stream. A #[Component], so the ProjectionRunner finds it;
 * `php artisan firefly:eventsourcing:projections:run` hands it every loan event after its checkpoint, in commit order,
 * one batch per transaction, and moves the checkpoint in that same transaction.
 *
 * It writes through the DB facade's default connection, which is the checkpoint store's connection while
 * firefly.eventsourcing.store.connection is null — so its rows and its checkpoint commit or roll back together, and
 * each event is projected exactly once. Every write names the row by its primary key.
 */
#[Component]
final class LoanBookProjection implements Projection
{
    public const string NAME = 'lumen.loan-book';

    public const string TABLE = 'loan_book';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return ['loan.granted', 'loan.repaid', 'loan.settled'];
    }

    public function handle(StoredEvent $event): void
    {
        $payload = $event->payload;
        $loan = DB::table(self::TABLE)->where('loan_id', $event->aggregateId);

        if ($event->eventType === 'loan.granted') {
            DB::table(self::TABLE)->insert([
                'loan_id' => $event->aggregateId,
                'wallet_id' => self::text($payload, 'walletId'),
                'currency' => self::text($payload, 'currency'),
                'principal_minor' => self::number($payload, 'principalMinor'),
                'repaid_minor' => 0,
                'outstanding_minor' => self::number($payload, 'principalMinor'),
                'repayments' => 0,
                'status' => 'open',
            ]);
        } elseif ($event->eventType === 'loan.repaid') {
            $loan->incrementEach(
                ['repaid_minor' => self::number($payload, 'amountMinor'), 'repayments' => 1],
                ['outstanding_minor' => self::number($payload, 'outstandingMinor')],
            );
        } else {
            $loan->update(['status' => 'settled']);
        }
    }

    public function reset(): void
    {
        DB::table(self::TABLE)->delete();
    }

    /** @param array<string, mixed> $payload */
    private static function number(array $payload, string $key): int
    {
        $value = $payload[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    /** @param array<string, mixed> $payload */
    private static function text(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
