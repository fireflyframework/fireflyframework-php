<?php

declare(strict_types=1);

use Firefly\Data\Repository\Projection\ProjectionHydrator;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

enum HydratedTier: string
{
    case Gold = 'gold';
    case Silver = 'silver';
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
        ->and(fn () => $hydrator->hydrate(['score' => 'not-a-number'] + $row))->toThrow(ConfigurationException::class, 'column [score] holds string')
        ->and(fn () => $hydrator->hydrate(['tier' => 'bronze'] + $row))->toThrow(ValueError::class);
});
