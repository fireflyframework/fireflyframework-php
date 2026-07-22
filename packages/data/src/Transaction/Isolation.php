<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

/**
 * Isolation levels. The backing value IS the SQL level string, so the interceptor issues
 * `SET TRANSACTION ISOLATION LEVEL {$isolation->value}` directly and the manifest serialises it verbatim.
 * DEFAULT means "leave the connection default" (no SET). Driver support varies (SQLite ignores it) —
 * documented latent.
 */
enum Isolation: string
{
    case DEFAULT = 'DEFAULT';
    case READ_UNCOMMITTED = 'READ UNCOMMITTED';
    case READ_COMMITTED = 'READ COMMITTED';
    case REPEATABLE_READ = 'REPEATABLE READ';
    case SERIALIZABLE = 'SERIALIZABLE';
}
