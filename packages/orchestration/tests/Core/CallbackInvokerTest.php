<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Callback\CallbackDefinition;
use Firefly\Orchestration\Core\Callback\CallbackInvoker;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Tests\Support\RecordingLogger;
use Illuminate\Container\Container;

it('runs sync callbacks by descending priority, then async ones, and isolates a failing one', function (): void {
    $order = [];
    $logger = new RecordingLogger;
    $invoker = new CallbackInvoker(new Container, $logger);
    $context = ExecutionContext::start(ExecutionPattern::Saga, 'order', [], [], 'c', new DateTimeImmutable);

    $invoker->invoke([
        CallbackDefinition::closure(static function () use (&$order): void {
            $order[] = 'async';
        }, async: true, priority: 100),
        CallbackDefinition::closure(static function () use (&$order): void {
            $order[] = 'low';
        }, priority: 1),
        CallbackDefinition::closure(static function (): never {
            throw new RuntimeException('hook broke');
        }, priority: 5),
        CallbackDefinition::closure(static function (ExecutionContext $ctx, mixed $result) use (&$order): void {
            $order[] = 'high:'.(is_string($result) ? $result : '');
        }, priority: 10),
    ], $context, 'done');

    expect($order)->toBe(['high:done', 'low', 'async'])
        ->and($logger->messages('warning')[0] ?? '')->toContain('hook broke');
});

it('filters error callbacks by error type and step callbacks by step id', function (): void {
    $seen = [];
    $invoker = new CallbackInvoker(new Container, new RecordingLogger);
    $context = ExecutionContext::start(ExecutionPattern::Workflow, 'w', [], [], 'c', new DateTimeImmutable);
    $callbacks = [
        CallbackDefinition::closure(static function () use (&$seen): void {
            $seen[] = 'logic';
        }, errorTypes: [LogicException::class]),
        CallbackDefinition::closure(static function () use (&$seen): void {
            $seen[] = 'any';
        }),
        CallbackDefinition::closure(static function () use (&$seen): void {
            $seen[] = 'ship';
        }, stepIds: ['ship']),
    ];

    $invoker->invoke($callbacks, $context, error: new RuntimeException('x'), stepId: 'pack');

    expect($seen)->toBe(['any'])
        ->and($invoker->suppresses([CallbackDefinition::closure(static fn (): null => null, errorTypes: [RuntimeException::class], suppressError: true)], new RuntimeException('x')))->toBeTrue()
        ->and($invoker->suppresses([CallbackDefinition::closure(static fn (): null => null, errorTypes: [LogicException::class], suppressError: true)], new RuntimeException('x')))->toBeFalse();
});

it('round-trips a method callback and refuses to compile a closure one', function (): void {
    $callback = new CallbackDefinition(class: 'App\\Saga', method: 'done', parameters: [CallbackDefinition::CONTEXT, CallbackDefinition::RESULT], priority: 3, errorTypes: [RuntimeException::class], suppressError: true, stepIds: ['a']);

    expect(CallbackDefinition::fromArray($callback->toArray()))->toEqual($callback)
        ->and(fn () => CallbackDefinition::closure(static fn (): null => null)->toArray())->toThrow(LogicException::class);
});
