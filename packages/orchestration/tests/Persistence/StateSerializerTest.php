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

it('refuses a cyclic object graph and names where the cycle closed', function (): void {
    $order = new stdClass;
    $line = new stdClass;
    $line->order = $order;
    $order->lines = [$line];

    StateSerializer::normalize(['reserve' => $order], 'stepResults');
})->throws(OrchestrationException::class, 'stepResults.reserve.lines.0.order holds a cyclic reference');

it('refuses an object that references itself with the unserializable code', function (): void {
    $self = new stdClass;
    $self->self = $self;

    $error = null;
    try {
        StateSerializer::normalize($self);
    } catch (OrchestrationException $caught) {
        $error = $caught;
    }

    expect($error)->toBeInstanceOf(OrchestrationException::class)
        ->and($error?->getMessage())->toContain('value.self holds a cyclic reference')
        ->and($error?->errorCode())->toBe('ORCHESTRATION_UNSERIALIZABLE');
});

it('normalizes the same object reached through sibling branches as a shared reference', function (): void {
    $customer = new stdClass;
    $customer->id = 7;

    expect(StateSerializer::normalize(['billing' => $customer, 'shipping' => $customer, 'again' => [$customer]]))
        ->toBe(['billing' => ['id' => 7], 'shipping' => ['id' => 7], 'again' => [['id' => 7]]]);
});

it('refuses a payload nested deeper than a checkpoint can read back', function (): void {
    $value = 'leaf';
    for ($i = 0; $i < 600; $i++) {
        $value = [$value];
    }

    StateSerializer::normalize($value);
})->throws(OrchestrationException::class, 'nests deeper than 512 levels');

it('round-trips an array payload through JSON keeping float zero fractions', function (): void {
    $payload = StateSerializer::encodeArray(['amount' => 10.0, 'name' => 'é']);

    expect($payload)->toBe('{"amount":10.0,"name":"é"}')
        ->and(StateSerializer::decodeArray($payload))->toBe(['amount' => 10.0, 'name' => 'é']);
});
