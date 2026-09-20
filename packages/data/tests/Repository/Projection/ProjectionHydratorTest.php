<?php

declare(strict_types=1);

use Firefly\Data\Repository\Projection\ProjectionHydrator;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

enum HydratedTier: string
{
    case Gold = 'gold';
    case Silver = 'silver';
}

enum HydratedLevel: int
{
    case Low = 1;
    case High = 3;
}

final readonly class HydratedRow
{
    public function __construct(
        public int $id,
        public float $score,
        public bool $active,
        public ?string $note,
        public HydratedTier $tier,
        public DateTimeImmutable $createdAt,
        public string $label = 'none',
    ) {}
}

/**
 * The row TransactionalScanner would compile for HydratedRow.
 *
 * @return array{dto: class-string, columns: list<string>, parameters: list<array{name: string, column: string, type: string|null, nullable: bool, optional: bool}>}
 */
function hydratedProjection(): array
{
    return [
        'dto' => HydratedRow::class,
        'columns' => ['id', 'score', 'active', 'note', 'tier', 'created_at', 'label'],
        'parameters' => [
            ['name' => 'id', 'column' => 'id', 'type' => 'int', 'nullable' => false, 'optional' => false],
            ['name' => 'score', 'column' => 'score', 'type' => 'float', 'nullable' => false, 'optional' => false],
            ['name' => 'active', 'column' => 'active', 'type' => 'bool', 'nullable' => false, 'optional' => false],
            ['name' => 'note', 'column' => 'note', 'type' => 'string', 'nullable' => true, 'optional' => false],
            ['name' => 'tier', 'column' => 'tier', 'type' => HydratedTier::class, 'nullable' => false, 'optional' => false],
            ['name' => 'createdAt', 'column' => 'created_at', 'type' => DateTimeImmutable::class, 'nullable' => false, 'optional' => false],
            ['name' => 'label', 'column' => 'label', 'type' => 'string', 'nullable' => false, 'optional' => true],
        ],
    ];
}

/** An int-backed enum and a mutable DateTime: the two coercion branches HydratedRow does not reach. */
final readonly class HydratedStamp
{
    public function __construct(
        public HydratedLevel $level,
        public DateTime $seenAt,
    ) {}
}

/**
 * @return array{dto: class-string, columns: list<string>, parameters: list<array{name: string, column: string, type: string|null, nullable: bool, optional: bool}>}
 */
function hydratedStampProjection(): array
{
    return [
        'dto' => HydratedStamp::class,
        'columns' => ['level', 'seen_at'],
        'parameters' => [
            ['name' => 'level', 'column' => 'level', 'type' => HydratedLevel::class, 'nullable' => false, 'optional' => false],
            ['name' => 'seenAt', 'column' => 'seen_at', 'type' => DateTime::class, 'nullable' => false, 'optional' => false],
        ],
    ];
}

it('coerces driver scalars to the typed constructor, snake_case to camelCase, and fills a missing optional from its default', function () {
    $dto = (new ProjectionHydrator(hydratedProjection()))->hydrate([
        'id' => '7', 'score' => '9.5', 'active' => 1, 'note' => null, 'tier' => 'gold', 'created_at' => '2026-01-02 03:04:05',
    ]);

    assert($dto instanceof HydratedRow);
    expect($dto->id)->toBe(7)
        ->and($dto->score)->toBe(9.5)
        ->and($dto->active)->toBeTrue()
        ->and($dto->note)->toBeNull()
        ->and($dto->tier)->toBe(HydratedTier::Gold)
        ->and($dto->createdAt->format('Y-m-d H:i:s'))->toBe('2026-01-02 03:04:05')
        ->and($dto->label)->toBe('none');
});

it('names the missing column and the parameter when a required column is absent', function () {
    expect(fn () => (new ProjectionHydrator(hydratedProjection()))->hydrate(['id' => 1]))
        ->toThrow(ConfigurationException::class, 'needs column [score] for parameter $score');
});

it('refuses a NULL for a non-nullable parameter and a value that cannot be coerced', function () {
    $hydrator = new ProjectionHydrator(hydratedProjection());
    $row = ['id' => 1, 'score' => 1.0, 'active' => true, 'note' => 'n', 'tier' => 'gold', 'created_at' => '2026-01-01'];

    expect(fn () => $hydrator->hydrate(['id' => null] + $row))->toThrow(ConfigurationException::class, 'column [id] is NULL')
        ->and(fn () => $hydrator->hydrate(['score' => 'not-a-number'] + $row))->toThrow(ConfigurationException::class, "column [score] holds string 'not-a-number', which cannot become parameter \$score (float)")
        ->and(fn () => $hydrator->hydrate(['active' => 'abc'] + $row))->toThrow(ConfigurationException::class, "column [active] holds string 'abc'")
        ->and(fn () => $hydrator->hydrate(['active' => 2] + $row))->toThrow(ConfigurationException::class, 'column [active] holds int 2')
        ->and(fn () => $hydrator->hydrate(['tier' => 'bronze'] + $row))->toThrow(ConfigurationException::class, "column [tier] holds string 'bronze', which cannot become parameter \$tier (HydratedTier)")
        ->and(fn () => $hydrator->hydrate(['tier' => 1.5] + $row))->toThrow(ConfigurationException::class, 'column [tier] holds float 1.5')
        ->and(fn () => $hydrator->hydrate(['created_at' => 'not a date'] + $row))->toThrow(ConfigurationException::class, "column [created_at] holds string 'not a date', which cannot become parameter \$createdAt (DateTimeImmutable)")
        ->and(fn () => $hydrator->hydrate(['created_at' => '2026-13-45'] + $row))->toThrow(ConfigurationException::class, "column [created_at] holds string '2026-13-45'")
        ->and(fn () => $hydrator->hydrate(['created_at' => []] + $row))->toThrow(ConfigurationException::class, 'column [created_at] holds array,');
});

it('refuses a numeric that is not an integer for an int parameter instead of truncating or saturating it', function () {
    // MySQL and PostgreSQL hand DECIMAL/NUMERIC back as strings: `int $amount` over such a column must fail on
    // '150.75', not read 150. Likewise a float with a fraction, an exponent spelling, hex, and a string past
    // PHP_INT_MAX that (int) would saturate to 9223372036854775807.
    $hydrator = new ProjectionHydrator(hydratedProjection());
    $row = ['score' => 1.0, 'active' => true, 'note' => 'n', 'tier' => 'gold', 'created_at' => '2026-01-01'];

    expect(fn () => $hydrator->hydrate(['id' => '150.75'] + $row))->toThrow(ConfigurationException::class, "column [id] holds string '150.75', which cannot become parameter \$id (int)")
        ->and(fn () => $hydrator->hydrate(['id' => 150.75] + $row))->toThrow(ConfigurationException::class, 'column [id] holds float 150.75, which cannot become parameter $id (int)')
        ->and(fn () => $hydrator->hydrate(['id' => '9223372036854775808'] + $row))->toThrow(ConfigurationException::class, "column [id] holds string '9223372036854775808'")
        ->and(fn () => $hydrator->hydrate(['id' => 1e20] + $row))->toThrow(ConfigurationException::class, 'column [id] holds float 1.0E+20')
        ->and(fn () => $hydrator->hydrate(['id' => '1e3'] + $row))->toThrow(ConfigurationException::class, "column [id] holds string '1e3'")
        ->and(fn () => $hydrator->hydrate(['id' => '0x1A'] + $row))->toThrow(ConfigurationException::class, "column [id] holds string '0x1A'")
        ->and(fn () => $hydrator->hydrate(['id' => ''] + $row))->toThrow(ConfigurationException::class, "column [id] holds string ''")
        ->and(fn () => $hydrator->hydrate(['id' => true] + $row))->toThrow(ConfigurationException::class, 'column [id] holds bool true')
        ->and(fn () => $hydrator->hydrate(['id' => NAN] + $row))->toThrow(ConfigurationException::class, 'column [id] holds float NAN');
});

it('accepts a PHP int, an integral string and an integral in-range float for an int parameter', function () {
    $hydrator = new ProjectionHydrator(hydratedProjection());
    $row = ['score' => 1.0, 'active' => true, 'note' => 'n', 'tier' => 'gold', 'created_at' => '2026-01-01'];

    // a list of pairs, not a map: PHP would fold the keys '7' and 150.0 into 7 and 150 and skip those cases
    foreach ([
        [7, 7],
        ['7', 7],
        ['-7', -7],
        ['+7', 7],
        [' 42 ', 42],
        ['0', 0],
        ['9223372036854775807', PHP_INT_MAX],
        ['-9223372036854775808', PHP_INT_MIN],
        [150.0, 150],
        [-0.0, 0],
        [1e15, 1_000_000_000_000_000],
    ] as [$given, $expected]) {
        $dto = $hydrator->hydrate(['id' => $given] + $row);
        assert($dto instanceof HydratedRow);
        expect($dto->id)->toBe($expected, sprintf('given %s', var_export($given, true)));
    }
});

it('shapes the scalar to the enum\'s backing type before the lookup, so an int-backed enum reads an integral string', function () {
    // emulated prepares (and SQLite text affinity) hand '3' back for an INT column; under strict_types
    // HydratedLevel::tryFrom('3') is a TypeError, so the hydrator must coerce first — with the int rules,
    // which still refuse '3.5'. A string-backed enum takes an int the way a string parameter does.
    $stamps = new ProjectionHydrator(hydratedStampProjection());
    $seen = ['seen_at' => '2026-01-02 03:04:05'];

    foreach ([3, '3', ' 3 ', 3.0] as $given) {
        $dto = $stamps->hydrate(['level' => $given] + $seen);
        assert($dto instanceof HydratedStamp);
        expect($dto->level)->toBe(HydratedLevel::High, sprintf('given %s', var_export($given, true)));
    }

    expect(fn () => $stamps->hydrate(['level' => '3.5'] + $seen))->toThrow(ConfigurationException::class, "column [level] holds string '3.5', which cannot become parameter \$level (HydratedLevel)")
        ->and(fn () => $stamps->hydrate(['level' => 2] + $seen))->toThrow(ConfigurationException::class, 'column [level] holds int 2, which cannot become parameter $level (HydratedLevel)')
        ->and(fn () => $stamps->hydrate(['level' => 'High'] + $seen))->toThrow(ConfigurationException::class, "column [level] holds string 'High'")
        ->and(fn () => $stamps->hydrate(['level' => true] + $seen))->toThrow(ConfigurationException::class, 'column [level] holds bool true');

    $tiers = new ProjectionHydrator(hydratedProjection());
    $row = ['id' => 1, 'score' => 1.0, 'active' => true, 'note' => 'n', 'created_at' => '2026-01-01'];
    expect(fn () => $tiers->hydrate(['tier' => 7] + $row))->toThrow(ConfigurationException::class, 'column [tier] holds int 7, which cannot become parameter $tier (HydratedTier)');
});

it('parses a DateTime the same way, refuses a blank string rather than reading it as now, and keeps the parser\'s exception as previous', function () {
    $stamps = new ProjectionHydrator(hydratedStampProjection());

    $dto = $stamps->hydrate(['level' => 1, 'seen_at' => '2026-01-02 03:04:05']);
    assert($dto instanceof HydratedStamp);
    expect($dto->seenAt)->toBeInstanceOf(DateTime::class)
        ->and($dto->seenAt->format('Y-m-d H:i:s'))->toBe('2026-01-02 03:04:05');

    $fromInterface = $stamps->hydrate(['level' => 1, 'seen_at' => new DateTimeImmutable('2025-12-31 23:59:59')]);
    assert($fromInterface instanceof HydratedStamp);
    expect($fromInterface->seenAt->format('Y-m-d H:i:s'))->toBe('2025-12-31 23:59:59');

    foreach (['', '   ', "\n"] as $blank) {
        expect(fn () => $stamps->hydrate(['level' => 1, 'seen_at' => $blank]))
            ->toThrow(ConfigurationException::class, 'column [seen_at] holds string');
    }

    try {
        $stamps->hydrate(['level' => 1, 'seen_at' => 'not a date']);
        $this->fail('expected a ConfigurationException');
    } catch (ConfigurationException $e) {
        expect($e->getMessage())->toBe("Projection [HydratedStamp]: column [seen_at] holds string 'not a date', which cannot become parameter \$seenAt (DateTime).")
            ->and($e->getPrevious())->toBeInstanceOf(DateMalformedStringException::class);
    }

    $immutable = new ProjectionHydrator(hydratedProjection());
    expect(fn () => $immutable->hydrate(['id' => 1, 'score' => 1.0, 'active' => true, 'note' => 'n', 'tier' => 'gold', 'created_at' => '']))
        ->toThrow(ConfigurationException::class, "column [created_at] holds string '', which cannot become parameter \$createdAt (DateTimeImmutable)");
});

it('cuts a long string at 64 characters in the mismatch sentence', function () {
    $hydrator = new ProjectionHydrator(hydratedProjection());
    $row = ['score' => 1.0, 'active' => true, 'note' => 'n', 'tier' => 'gold', 'created_at' => '2026-01-01'];

    expect(fn () => $hydrator->hydrate(['id' => str_repeat('x', 100)] + $row))
        ->toThrow(ConfigurationException::class, "column [id] holds string '".str_repeat('x', 64)."…', which cannot become parameter \$id (int)");
});

it('accepts only the boolean spellings a driver actually hands back for a bool parameter', function () {
    $hydrator = new ProjectionHydrator(hydratedProjection());
    $row = ['id' => 1, 'score' => 1.0, 'note' => 'n', 'tier' => 'gold', 'created_at' => '2026-01-01'];

    foreach ([true, 1, '1', 'true', 'on', 'yes'] as $truthy) {
        $dto = $hydrator->hydrate(['active' => $truthy] + $row);
        assert($dto instanceof HydratedRow);
        expect($dto->active)->toBeTrue();
    }

    foreach ([false, 0, '0', 'false', 'off', 'no', ''] as $falsy) {
        $dto = $hydrator->hydrate(['active' => $falsy] + $row);
        assert($dto instanceof HydratedRow);
        expect($dto->active)->toBeFalse();
    }
});
