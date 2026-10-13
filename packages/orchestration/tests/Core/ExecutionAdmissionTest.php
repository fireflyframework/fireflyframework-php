<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Infrastructure\CircuitBreakerOpenException;
use Firefly\Orchestration\Core\Exception\BackpressureException;
use Firefly\Orchestration\Core\ExecutionAdmission;
use Firefly\Orchestration\Persistence\MemoryExecutionPersistenceProvider;
use Firefly\Orchestration\Settings\BackpressureSettings;
use Firefly\Orchestration\Tests\Support\ExecutionPersistenceContract;
use Firefly\Resilience\CircuitBreaker;
use Firefly\Resilience\Store\InMemoryResilienceStore;

it('refuses a new execution once max-in-flight executions are open', function (): void {
    $persistence = new MemoryExecutionPersistenceProvider;
    $persistence->save(ExecutionPersistenceContract::state('a'));
    $admission = new ExecutionAdmission($persistence, new BackpressureSettings('none', 10, 1, 5, 30.0, 3));

    $admission->admit('order', static fn (): stdClass => new stdClass, static fn (stdClass $r): bool => true);
})->throws(BackpressureException::class, 'max-in-flight');

it('opens the breaker after failed executions and returns each failed result while closed', function (): void {
    $breaker = new CircuitBreaker(key: 'test:backpressure', store: new InMemoryResilienceStore, failureThreshold: 2, waitDurationInOpen: 60.0, halfOpenMaxCalls: 1);
    $admission = new ExecutionAdmission(new MemoryExecutionPersistenceProvider, new BackpressureSettings('circuit-breaker', 10, 0, 2, 60.0, 1), $breaker);
    $failed = static fn (): ArrayObject => new ArrayObject(['success' => false]);
    $succeeded = static fn (ArrayObject $result): bool => $result['success'] === true;

    expect($admission->admit('order', $failed, $succeeded)['success'])->toBeFalse()
        ->and($admission->admit('order', $failed, $succeeded)['success'])->toBeFalse()
        ->and(fn () => $admission->admit('order', $failed, $succeeded))->toThrow(CircuitBreakerOpenException::class);
});
