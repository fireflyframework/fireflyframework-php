<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Orchestration\Saga\Compensation\CompensationErrorHandler;
use Firefly\Orchestration\Saga\Compensation\CompensationErrorHandlerFactory;
use Firefly\Orchestration\Saga\Compensation\CompensationErrorResult;
use Firefly\Orchestration\Saga\Compensation\CompositeCompensationErrorHandler;
use Firefly\Orchestration\Saga\Compensation\DefaultCompensationErrorHandler;
use Firefly\Orchestration\Saga\Compensation\FailFastCompensationErrorHandler;
use Firefly\Orchestration\Saga\Compensation\RetryWithBackoffCompensationErrorHandler;
use Firefly\Orchestration\Tests\Support\RecordingLogger;
use Illuminate\Container\Container;

final class OrchestrationSkipEverything implements CompensationErrorHandler
{
    public function handle(string $sagaName, string $stepId, Throwable $error, int $attempt): CompensationErrorResult
    {
        return CompensationErrorResult::SkipStep;
    }
}

it('answers like the jafly handlers', function (): void {
    $error = new RuntimeException('x');
    $retry = new RetryWithBackoffCompensationErrorHandler(2, [RuntimeException::class]);

    expect((new DefaultCompensationErrorHandler)->handle('s', 'a', $error, 0))->toBe(CompensationErrorResult::Continue)
        ->and((new FailFastCompensationErrorHandler)->handle('s', 'a', $error, 0))->toBe(CompensationErrorResult::FailSaga)
        ->and($retry->handle('s', 'a', $error, 0))->toBe(CompensationErrorResult::Retry)
        ->and($retry->handle('s', 'a', $error, 2))->toBe(CompensationErrorResult::FailSaga)
        ->and($retry->handle('s', 'a', new LogicException('y'), 0))->toBe(CompensationErrorResult::FailSaga)
        ->and((new CompositeCompensationErrorHandler([new DefaultCompensationErrorHandler, new FailFastCompensationErrorHandler]))->handle('s', 'a', $error, 0))->toBe(CompensationErrorResult::FailSaga);
});

it('resolves the built-in names, a robust chain, and an application class by name', function (): void {
    $logger = new RecordingLogger;
    $factory = new CompensationErrorHandlerFactory(new Container, $logger);
    $robust = $factory->get('robust');

    expect($factory->names())->toBe(['default', 'fail-fast', 'log-and-continue', 'retry', 'robust'])
        ->and($factory->get('log-and-continue')->handle('s', 'a', new RuntimeException('x'), 0))->toBe(CompensationErrorResult::Continue)
        ->and($robust->handle('s', 'a', new RuntimeException('x'), 0))->toBe(CompensationErrorResult::Retry)
        ->and($robust->handle('s', 'a', new RuntimeException('x'), 3))->toBe(CompensationErrorResult::Continue)
        ->and($factory->get(OrchestrationSkipEverything::class)->handle('s', 'a', new RuntimeException('x'), 0))->toBe(CompensationErrorResult::SkipStep)
        ->and($logger->messages('warning'))->not->toBe([]);
});

it('refuses an unknown handler name, listing the known ones', function (): void {
    (new CompensationErrorHandlerFactory(new Container, new RecordingLogger))->get('pessimistic');
})->throws(ConfigurationException::class, 'default, fail-fast, log-and-continue, retry, robust');
