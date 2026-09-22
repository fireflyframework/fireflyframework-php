<?php

declare(strict_types=1);

use Firefly\Observability\Logging\Formatter\EcsFormatter;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * @param  array<string, mixed>  $context
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function ecsLine(array $context = [], array $extra = [], Level $level = Level::Info): array
{
    $record = new LogRecord(new DateTimeImmutable('2026-09-20T10:11:12.345678+00:00'), 'stack', $level, 'Order 42 shipped', $context, $extra);
    $line = (new EcsFormatter)->format($record);

    expect($line)->toEndWith("\n");

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('emits the ECS 8 core fields and maps the framework ids to trace/span/service/labels', function () {
    $line = ecsLine(['order' => 42], [
        'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
        'span_id' => '00f067aa0ba902b7',
        'correlation_id' => 'corr-1',
        'request_id' => 'req-1',
        'service_name' => 'ledger',
        'service_environment' => 'production',
        'memory' => 12,
    ]);

    expect($line)->toBe([
        '@timestamp' => '2026-09-20T10:11:12.345678+00:00',
        'log.level' => 'info',
        'message' => 'Order 42 shipped',
        'ecs.version' => EcsFormatter::ECS_VERSION,
        'log' => ['logger' => 'stack'],
        'service' => ['name' => 'ledger', 'environment' => 'production'],
        'trace' => ['id' => '4bf92f3577b34da6a3ce929d0e0e4736'],
        'span' => ['id' => '00f067aa0ba902b7'],
        'labels' => ['correlation_id' => 'corr-1', 'request_id' => 'req-1'],
        'context' => ['order' => 42],
        'extra' => ['memory' => 12],
    ]);
});

it('renders an exception in context as error.{type,message,stack_trace}', function () {
    $line = ecsLine(['exception' => new RuntimeException('boom')], [], Level::Error);
    $error = $line['error'] ?? null;

    expect($line['log.level'])->toBe('error')
        ->and($error)->toBeArray()->toMatchArray(['type' => RuntimeException::class, 'message' => 'boom'])
        ->and(is_array($error) ? ($error['stack_trace'] ?? null) : null)->toBeString()->toContain('#0')
        ->and($line)->not->toHaveKey('context');
});

it('omits every optional group that has nothing to say', function () {
    expect(array_keys(ecsLine()))->toBe(['@timestamp', 'log.level', 'message', 'ecs.version', 'log']);
});
