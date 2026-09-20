<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use PDO;
use PDOException;

/**
 * A mysql-grammar connection over an in-memory sqlite PDO for the StatementTimeoutApplier tests. pretend()
 * alone answers every SELECT with nothing, so the restore branch — "put the previous session value back" —
 * would never run; this connection ANSWERS a `select @@session.<variable>` with the canned previous value
 * (still logging it, so the statement order is asserted too), refuses the variables it is told the server
 * does not know the way a real server would (a QueryException over an "Unknown system variable"), and can
 * pose as a mariadb server behind a `mysql` driver. Every other statement goes through pretend() as usual.
 */
final class SessionVariableConnection extends MySqlConnection
{
    /**
     * @param  array<string, int|float|string>  $previous  variable => the session value the server reports
     * @param  list<string>  $unknown  variables the server refuses with "Unknown system variable"
     */
    public function __construct(
        string $driver,
        private readonly array $previous = [],
        private readonly array $unknown = [],
        private readonly bool $maria = false,
        string $name = 'pretend',
    ) {
        parent::__construct(new PDO('sqlite::memory:'), 'pretend', '', ['driver' => $driver, 'name' => $name]);
    }

    /**
     * @param  string  $query
     * @param  array<int|string, mixed>  $bindings
     * @param  bool  $useReadPdo
     */
    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        foreach ($this->unknown as $variable) {
            if (str_contains($query, '@@session.'.$variable)) {
                throw new QueryException('pretend', $query, [], new PDOException(sprintf("SQLSTATE[HY000]: General error: 1193 Unknown system variable '%s'", $variable)));
            }
        }

        parent::selectOne($query, $bindings, $useReadPdo);

        foreach ($this->previous as $variable => $value) {
            if (str_contains($query, '@@session.'.$variable)) {
                return (object) ['value' => $value];
            }
        }

        return null;
    }

    public function isMaria(): bool
    {
        return $this->maria;
    }
}
