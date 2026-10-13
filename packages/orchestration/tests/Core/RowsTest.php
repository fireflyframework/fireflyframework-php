<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Rows;

it('reads typed values with defaults and never throws on a malformed row', function (): void {
    $row = ['s' => 'x', 'i' => '7', 'f' => 2, 'b' => true, 'l' => ['a', 1, null], 'm' => [1 => 'one'], 'r' => [['k' => 'v'], 'skip'], 'bad' => new stdClass];

    expect(Rows::string($row, 's'))->toBe('x')
        ->and(Rows::string($row, 'bad', 'd'))->toBe('d')
        ->and(Rows::nullableString($row, 'missing'))->toBeNull()
        ->and(Rows::int($row, 'i'))->toBe(7)
        ->and(Rows::float($row, 'f'))->toBe(2.0)
        ->and(Rows::bool($row, 'b'))->toBeTrue()
        ->and(Rows::bool($row, 's'))->toBeFalse()
        ->and(Rows::strings($row, 'l'))->toBe(['a', '1'])
        ->and(Rows::map($row, 'm'))->toBe(['1' => 'one'])
        ->and(Rows::stringMap(['m' => ['t' => true, 'n' => 3]], 'm'))->toBe(['t' => 'true', 'n' => '3'])
        ->and(Rows::intMap(['m' => ['a' => '2', 'b' => 'x']], 'm'))->toBe(['a' => 2])
        ->and(Rows::rows($row, 'r'))->toBe([['k' => 'v']])
        ->and(Rows::nullableMap($row, 's'))->toBeNull();
});
