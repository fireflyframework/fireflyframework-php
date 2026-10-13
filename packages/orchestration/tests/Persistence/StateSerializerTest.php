<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Persistence\StateSerializer;

final class OrchestrationSerializerMoney implements JsonSerializable
{
    public function __construct(private readonly int $cents) {}

    /** @return array{cents: int} */
    public function jsonSerialize(): array
    {
        return ['cents' => $this->cents];
    }
}

final class OrchestrationSerializerPlain
{
    public string $sku = 'A-1';

    private string $secret = 'hidden';

    public function secret(): string
    {
        return $this->secret;
    }
}

it('normalizes every value a step may return into its JSON-safe form', function (): void {
    $normalized = StateSerializer::normalize([
        'money' => new OrchestrationSerializerMoney(1250),
        'pattern' => ExecutionPattern::Saga,
        'at' => new DateTimeImmutable('2026-10-12T10:00:00.123456+02:00'),
        'plain' => new OrchestrationSerializerPlain,
        'list' => new ArrayIterator([1, 2]),
        'scalar' => 1.0,
    ]);

    expect($normalized)->toBe([
        'money' => ['cents' => 1250],
        'pattern' => 'SAGA',
        'at' => '2026-10-12T08:00:00.123456+00:00',
        'plain' => ['sku' => 'A-1'],
        'list' => [1, 2],
        'scalar' => 1.0,
    ]);
});

it('refuses a closure and names where it sat', function (): void {
    StateSerializer::normalize(['results' => ['charge' => static fn (): int => 1]], 'stepResults');
})->throws(OrchestrationException::class, 'stepResults.results.charge');

it('round-trips an array payload through JSON keeping float zero fractions', function (): void {
    $payload = StateSerializer::encodeArray(['amount' => 10.0, 'name' => 'é']);

    expect($payload)->toBe('{"amount":10.0,"name":"é"}')
        ->and(StateSerializer::decodeArray($payload))->toBe(['amount' => 10.0, 'name' => 'é']);
});
