<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

/** Most-severe-wins aggregation (Spring's SimpleStatusAggregator). An empty set aggregates to UP. */
final class StatusAggregator
{
    /**
     * @param  list<Status>  $statuses
     */
    public function aggregate(array $statuses): Status
    {
        $winner = null;
        foreach ($statuses as $status) {
            if ($winner === null || $status->severity() > $winner->severity()) {
                $winner = $status;
            }
        }

        return $winner ?? Status::Up;
    }
}
