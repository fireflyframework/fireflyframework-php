<?php

declare(strict_types=1);

use Firefly\EventSourcing\Event\PayloadNormalizer;
use Firefly\EventSourcing\Exception\EventSerializationException;

enum PayloadNormalizerSuit: string
{
    case Hearts = 'H';
}

it('normalizes dates, enums and JsonSerializable values to what JSON can hold', function (): void {
    $json = new class implements JsonSerializable
    {
        /** @return array{n: int} */
        public function jsonSerialize(): array
        {
            return ['n' => 1];
        }
    };

    expect(PayloadNormalizer::normalize([
        'at' => new DateTimeImmutable('2026-10-13 10:00:00.123456', new DateTimeZone('UTC')),
        'suit' => PayloadNormalizerSuit::Hearts,
        'json' => $json,
        'list' => [1, 2.5, 'x', null, true],
    ]))->toBe([
        'at' => '2026-10-13T10:00:00.123456+00:00',
        'suit' => 'H',
        'json' => ['n' => 1],
        'list' => [1, 2.5, 'x', null, true],
    ]);
});

it('refuses a value an event store cannot hold, naming where it sat', function (mixed $value, string $path): void {
    expect(fn () => PayloadNormalizer::normalize(['lines' => [$value]]))->toThrow(EventSerializationException::class, $path);
})->with([
    'closure' => [static fn (): int => 1, 'payload.lines.0'],
    'object' => [new stdClass, 'payload.lines.0'],
    'non-finite float' => [INF, 'payload.lines.0'],
]);
