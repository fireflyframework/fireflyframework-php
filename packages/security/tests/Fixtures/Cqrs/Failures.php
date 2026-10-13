<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Cqrs;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\Business\ConflictException;
use Firefly\Kernel\Exception\FireflyException;
use Illuminate\Database\QueryException;
use LogicException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The throwables a handler — or a controller — raises in the bus failure tests, built fresh on every call so a
 * controller and a handler throw equal but distinct instances.
 */
final class Failures
{
    public const string SQL = 'select * from users where token = ?';

    public const string BINDING = 'tok_live_5ecret';

    public static function make(string $failure): Throwable
    {
        return match ($failure) {
            'runtime' => new RuntimeException('SELECT secret FROM users'),
            'query' => new QueryException('sqlite', self::SQL, [self::BINDING], new PDOException('SQLSTATE[HY000]: General error: 1 no such table: users')),
            'conflict' => (new ConflictException('The wallet cannot be overdrawn.', 'WALLET_OVERDRAWN'))->withExtensions(['field' => 'amount']),
            'unavailable' => new FireflyException('The ledger is closed for the night; try again after 06:00.', 'LEDGER_CLOSED', 503, ErrorCategory::Infrastructure, ErrorSeverity::Error),
            default => new LogicException("Unknown failure [{$failure}]."),
        };
    }
}
