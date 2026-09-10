<?php

declare(strict_types=1);

use Firefly\Cqrs\Exception\CommandHandlerNotFoundException;
use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\Cqrs\Exception\CqrsConfigurationException;
use Firefly\Cqrs\Exception\CqrsException;
use Firefly\Cqrs\Exception\QueryProcessingException;
use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Kernel\Exception\FireflyException;

it('roots every cqrs exception under CqrsException and FireflyException', function () {
    expect(new CqrsConfigurationException('x'))->toBeInstanceOf(CqrsException::class)
        ->and(new CqrsConfigurationException('x'))->toBeInstanceOf(FireflyException::class)
        ->and(new CommandHandlerNotFoundException('App\CreateOrder'))->toBeInstanceOf(CqrsException::class);
});

it('gives handler-not-found a framework 500 and configuration a critical framework error', function () {
    $notFound = new CommandHandlerNotFoundException('App\CreateOrder');
    expect($notFound->errorCode())->toBe('COMMAND_HANDLER_NOT_FOUND')
        ->and($notFound->httpStatus())->toBe(500)
        ->and($notFound->category())->toBe(ErrorCategory::Framework)
        ->and($notFound->getMessage())->toContain('App\CreateOrder');

    $config = new CqrsConfigurationException('duplicate handler');
    expect($config->errorCode())->toBe('CQRS_CONFIGURATION_ERROR')
        ->and($config->severity())->toBe(ErrorSeverity::Critical);
});

it('preserves the cause code/category/severity/httpStatus when wrapping (does not mask to 500)', function () {
    $cause = new ValidationException('bad input');
    $wrapped = new CommandProcessingException('App\CreateOrder', $cause);

    expect($wrapped)->toBeInstanceOf(CqrsException::class)
        ->and($wrapped->errorCode())->toBe($cause->errorCode()) // VALIDATION_ERROR, NOT COMMAND_PROCESSING_ERROR
        ->and($wrapped->httpStatus())->toBe($cause->httpStatus()) // 422, NOT 500
        ->and($wrapped->category())->toBe($cause->category())
        ->and($wrapped->severity())->toBe($cause->severity())
        ->and($wrapped->getPrevious())->toBe($cause)
        ->and($wrapped->getMessage())->toContain('App\CreateOrder');
});

it('falls back to internal 500 when the wrapped cause is a plain Throwable, preserving the cause chain', function () {
    $cause = new RuntimeException('boom');
    $wrapped = new QueryProcessingException('App\FindOrder', $cause);

    expect($wrapped->httpStatus())->toBe(500)
        ->and($wrapped->category())->toBe(ErrorCategory::Internal)
        ->and($wrapped->severity())->toBe(ErrorSeverity::Error)
        ->and($wrapped->errorCode())->toBe('QUERY_PROCESSING_ERROR')
        ->and($wrapped->getPrevious())->toBe($cause); // the cause chain is preserved
});

/*
 | The error CODE is part of the fault's identity, exactly as its status, category and severity are.
 |
 | Copying three of the four left every domain failure indistinguishable on the wire: a duplicate came
 | back as `409 COMMAND_PROCESSING_ERROR`, a missing row as `404 COMMAND_PROCESSING_ERROR`, and a caller
 | had no way to branch on which had happened. Applications worked around it by catching the wrapper and
 | rethrowing `getPrevious()` in every controller that dispatched a command.
 */
it('carries the cause error code through both wrappers', function (string $class, string $subject) {
    $cause = new ResourceNotFoundException('no such room', 'ROOM_NOT_FOUND');
    /** @var CqrsException $wrapped */
    $wrapped = new $class($subject, $cause);

    expect($wrapped->errorCode())->toBe('ROOM_NOT_FOUND')
        ->and($wrapped->httpStatus())->toBe(404)
        ->and($wrapped->getPrevious())->toBe($cause);
})->with([
    'command' => [CommandProcessingException::class, 'App\IngestMessage'],
    'query' => [QueryProcessingException::class, 'App\FindRoom'],
]);

it('keeps its own generic code when the cause is not a FireflyException', function () {
    expect((new CommandProcessingException('App\CreateOrder', new RuntimeException('boom')))->errorCode())
        ->toBe('COMMAND_PROCESSING_ERROR')
        ->and((new QueryProcessingException('App\FindOrder', new RuntimeException('boom')))->errorCode())
        ->toBe('QUERY_PROCESSING_ERROR');
});
