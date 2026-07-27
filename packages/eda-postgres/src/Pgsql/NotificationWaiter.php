<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Pgsql;

use PDO;
use Pdo\Pgsql;

/**
 * Blocks up to $timeoutMs for a Postgres NOTIFY. On PHP 8.4+ uses the namespaced Pdo\Pgsql::getNotify() (PDO::
 * pgsqlGetNotify() is DEPRECATED in 8.5 — this repo runs 8.5.8); on 8.3 falls back to PDO::pgsqlGetNotify().
 * Laravel builds a base PDO (not the Pdo\Pgsql factory subclass), so the instanceof gate is required: getNotify()
 * exists only on the subclass, and the base-PDO path uses the (8.5-deprecated but still functional) pgsqlGetNotify().
 */
final class NotificationWaiter
{
    /**
     * The NOTIFY payload (message + notifying backend pid) or null on timeout. The caller uses it only as a wake
     * signal — the PENDING claim that follows is the source of truth — so the exact shape is not relied upon.
     *
     * @return array<int|string, mixed>|null
     */
    public static function wait(PDO $pdo, int $timeoutMs): ?array
    {
        if (PHP_VERSION_ID >= 80400 && $pdo instanceof Pgsql) {
            $result = $pdo->getNotify(PDO::FETCH_ASSOC, $timeoutMs);
        } else {
            $result = $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, $timeoutMs);
        }

        return $result === false ? null : $result;
    }
}
