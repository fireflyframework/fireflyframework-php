<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Outbox\Claim;

use Firefly\Eda\Database\Outbox\OutboxRow;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * The predicates every claim strategy, the consumer and the relay share, so "claimable" and "still mine" mean one
 * thing everywhere: claimable = PENDING and (no lease, or a lease that ended before now); mine = PENDING and leased
 * by this claimant. Settling a row through owned() is what keeps a claimant whose lease ran out from settling a row
 * another claimant has since taken.
 */
final class ClaimableRows
{
    public static function query(Connection $connection, OutboxClaim $claim): Builder
    {
        return $connection->table(OutboxSchema::TABLE)
            ->where('status', OutboxSchema::STATUS_PENDING)
            ->where(static function (Builder $query) use ($claim): void {
                $query->whereNull('claimed_until')->orWhere('claimed_until', '<', $claim->nowString());
            });
    }

    public static function candidates(Connection $connection, OutboxClaim $claim): Builder
    {
        return self::query($connection, $claim)->select('id')->orderBy('id')->limit($claim->limit);
    }

    /**
     * @return list<int>
     */
    public static function ids(Builder $query): array
    {
        $ids = [];
        foreach ($query->pluck('id') as $id) {
            $ids[] = OutboxRow::asInt($id);
        }

        return $ids;
    }

    /**
     * @return array{claimed_by: string, claimed_until: string}
     */
    public static function leaseColumns(OutboxClaim $claim): array
    {
        return ['claimed_by' => $claim->claimant, 'claimed_until' => $claim->untilString()];
    }

    /**
     * @param  list<int>  $ids
     */
    public static function lease(Connection $connection, array $ids, OutboxClaim $claim): void
    {
        if ($ids === []) {
            return;
        }

        $connection->table(OutboxSchema::TABLE)->whereIn('id', $ids)->update(self::leaseColumns($claim));
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, stdClass>
     */
    public static function load(Connection $connection, array $ids, string $claimant): Collection
    {
        if ($ids === []) {
            /** @var Collection<int, stdClass> $none */
            $none = new Collection;

            return $none;
        }

        /** @var Collection<int, stdClass> $rows */
        $rows = $connection->table(OutboxSchema::TABLE)
            ->whereIn('id', $ids)
            ->where('claimed_by', $claimant)
            ->orderBy('id')
            ->get();

        return $rows;
    }

    public static function owned(ConnectionInterface $connection, int $id, string $claimant): Builder
    {
        return $connection->table(OutboxSchema::TABLE)
            ->where('id', $id)
            ->where('status', OutboxSchema::STATUS_PENDING)
            ->where('claimed_by', $claimant);
    }
}
