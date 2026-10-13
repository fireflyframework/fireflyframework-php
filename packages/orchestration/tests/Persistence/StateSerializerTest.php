<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
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
})->throws(OrchestrationException::class, 'nests deeper than 510 levels');

/**
 * A step result of $arrays nested arrays, wrapped the way a checkpoint wraps it: the envelope root, the
 * `stepResults` map, then the step id.
 */
function orchestrationSerializerDeepContext(int $arrays): ExecutionContext
{
    $value = 'leaf';
    for ($i = 0; $i < $arrays; $i++) {
        $value = [$value];
    }

    $context = ExecutionContext::start(ExecutionPattern::Saga, 'deep', [], [], 'c-deep', new DateTimeImmutable('2026-10-12T10:00:00+00:00'));
    $context->putResult('deep', $value);

    return $context;
}

/**
 * @return array{string, string}|null the error code and message, or null when nothing was thrown
 */
function orchestrationSerializerRefusal(callable $action): ?array
{
    try {
        $action();
    } catch (OrchestrationException $caught) {
        return [$caught->errorCode(), $caught->getMessage()];
    }

    return null;
}

it('checkpoints and resumes a step result nested exactly as deep as the envelope leaves room for', function (): void {
    // The stepResults map is the field's first array, so the step result itself may nest MAX_DEPTH - 1.
    $state = orchestrationSerializerDeepContext(StateSerializer::MAX_DEPTH - 1)->toState(ExecutionStatus::Running, new DateTimeImmutable('2026-10-12T10:00:01+00:00'));

    $back = StateSerializer::decode(StateSerializer::encode($state));

    expect($back->stepResults)->toBe($state->stepResults);
});

it('refuses a step result one level deeper than a checkpoint can read back, before it is written', function (): void {
    $context = orchestrationSerializerDeepContext(StateSerializer::MAX_DEPTH);

    $refusal = orchestrationSerializerRefusal(static fn () => $context->toState(ExecutionStatus::Running, new DateTimeImmutable('2026-10-12T10:00:01+00:00')));

    expect($refusal)->not->toBeNull()
        ->and($refusal[0] ?? null)->toBe('ORCHESTRATION_UNSERIALIZABLE')
        ->and($refusal[1] ?? '')->toContain('stepResults.deep.0.0')
        ->and($refusal[1] ?? '')->toContain('nests deeper than 510 levels');
});

it('refuses a non-finite float with its path and the unserializable code', function (float $float): void {
    $refusal = orchestrationSerializerRefusal(static fn () => StateSerializer::normalize(['quote' => ['rate' => $float]], 'stepResults'));

    expect($refusal)->not->toBeNull()
        ->and($refusal[0] ?? null)->toBe('ORCHESTRATION_UNSERIALIZABLE')
        ->and($refusal[1] ?? '')->toContain('stepResults.quote.rate holds a non-finite float');
})->with([
    'NAN' => [NAN],
    'INF' => [INF],
    '-INF' => [-INF],
]);

it('refuses a string that is not valid UTF-8 with its path and the unserializable code', function (): void {
    $refusal = orchestrationSerializerRefusal(static fn () => StateSerializer::normalize(['load' => ['blob' => "\xB1\x31"]], 'stepResults'));

    expect($refusal)->not->toBeNull()
        ->and($refusal[0] ?? null)->toBe('ORCHESTRATION_UNSERIALIZABLE')
        ->and($refusal[1] ?? '')->toContain('stepResults.load.blob holds a string that is not valid UTF-8');
});

it('refuses an array key that is not valid UTF-8 with the unserializable code', function (): void {
    $refusal = orchestrationSerializerRefusal(static fn () => StateSerializer::normalize(['load' => ["\xB1\x31" => 1]], 'stepResults'));

    expect($refusal)->not->toBeNull()
        ->and($refusal[0] ?? null)->toBe('ORCHESTRATION_UNSERIALIZABLE')
        ->and($refusal[1] ?? '')->toContain('stepResults.load holds a key that is not valid UTF-8');
});

it('refuses through toState() too, so every provider rejects the value before a checkpoint exists', function (): void {
    $context = ExecutionContext::start(ExecutionPattern::Saga, 's', [], [], 'c-nan', new DateTimeImmutable('2026-10-12T10:00:00+00:00'));
    $context->putResult('price', NAN);

    $refusal = orchestrationSerializerRefusal(static fn () => $context->toState(ExecutionStatus::Running, new DateTimeImmutable('2026-10-12T10:00:01+00:00')));

    expect($refusal[0] ?? null)->toBe('ORCHESTRATION_UNSERIALIZABLE');
});

it('rethrows a JSON encoding failure in a field normalize() never saw with the unserializable code', function (): void {
    $refusal = orchestrationSerializerRefusal(static fn () => StateSerializer::encodeArray(['headers' => ['x-raw' => "\xB1\x31"]]));

    expect($refusal)->not->toBeNull()
        ->and($refusal[0] ?? null)->toBe('ORCHESTRATION_UNSERIALIZABLE')
        ->and($refusal[1] ?? '')->toContain('Malformed UTF-8');
});

it('round-trips an array payload through JSON keeping float zero fractions', function (): void {
    $payload = StateSerializer::encodeArray(['amount' => 10.0, 'name' => 'é']);

    expect($payload)->toBe('{"amount":10.0,"name":"é"}')
        ->and(StateSerializer::decodeArray($payload))->toBe(['amount' => 10.0, 'name' => 'é']);
});
