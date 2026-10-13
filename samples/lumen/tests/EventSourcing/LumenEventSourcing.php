<?php

declare(strict_types=1);

namespace Lumen\Tests\EventSourcing;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\EventSourcing\EventSourcingServiceProvider;
use Firefly\EventSourcing\EventSourcingWiringProvider;
use Firefly\EventSourcing\Manifest\EventSourcingManifest;
use Firefly\EventSourcing\Scanner\EventSourcingScanner;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
use Lumen\Application\Loan\GrantLoan;
use Lumen\Domain\Currency;

/**
 * What every Lumen harness that boots firefly/eventsourcing shares, whatever it extends: the package's two providers,
 * the database event store (snapshots and checkpoints follow it) with a snapshot every third event, the app's
 * #[EventType] classes compiled INLINE by the real scanner (what firefly:cache writes), freshly created eventsourcing
 * tables, and the helpers the tests drive the sample with. Helpers are methods here, never Pest functions, because a
 * global function declared in two test files is a fatal redeclaration.
 */
trait LumenEventSourcing
{
    /** @return list<class-string<ServiceProvider>> */
    protected static function eventSourcingProviders(): array
    {
        return [EventSourcingServiceProvider::class, EventSourcingWiringProvider::class];
    }

    /** @return array<string, mixed> */
    protected static function eventSourcingConfig(): array
    {
        return [
            'firefly.eventsourcing.store.provider' => 'database',
            // 50 by default; 3 lets a short test cross the threshold.
            'firefly.eventsourcing.snapshot.threshold' => 3,
        ];
    }

    protected function bindEventSourcingManifest(Application $app): void
    {
        $app->instance(EventSourcingManifest::class, (new EventSourcingScanner)->scan($this->appPsr4()));
    }

    /** Dropped first: on a real server (the integration subclasses) the last run's tables remain. */
    protected function freshEventSourcingTables(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        EventSourcingSchema::drop($schema);
        EventSourcingSchema::create($schema);
    }

    public function commands(): CommandBus
    {
        $bus = $this->fireflyContext()->get(CommandBus::class);

        return $bus instanceof CommandBus ? $bus : throw new LogicException('The CommandBus is not bound.');
    }

    public function queries(): QueryBus
    {
        $bus = $this->fireflyContext()->get(QueryBus::class);

        return $bus instanceof QueryBus ? $bus : throw new LogicException('The QueryBus is not bound.');
    }

    /** A lender: ADMIN may grant a loan, and may withdraw or repay for any wallet. */
    public function signInAdmin(): void
    {
        SecurityContextHolder::setContext(new SecurityContext(
            Authentication::authenticated('lender', 'lender', [new SimpleGrantedAuthority('ROLE_ADMIN')])
        ));
    }

    /** Opens a EUR wallet holding $balanceMinor and returns its id. */
    public function openWallet(int $balanceMinor = 0): string
    {
        $id = $this->commands()->send(new OpenWallet('owner-loans', Currency::EUR));
        if (! is_string($id)) {
            throw new LogicException('OpenWallet answers the new wallet id.');
        }
        if ($balanceMinor > 0) {
            $this->commands()->send(new Deposit($id, $balanceMinor));
        }

        return $id;
    }

    /** Grants a loan of $principalMinor into $walletId through the bus and returns the loan id. */
    public function grantLoan(string $walletId, int $principalMinor): string
    {
        $id = $this->commands()->send(new GrantLoan($walletId, $principalMinor));
        if (! is_string($id)) {
            throw new LogicException('GrantLoan answers the new loan id.');
        }

        return $id;
    }
}
