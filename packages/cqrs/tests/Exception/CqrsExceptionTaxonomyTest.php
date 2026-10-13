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

/*
 | The extension members and the title are part of the fault's identity on the wire too.
 |
 | ErrorResponse::fromException() reads both off the exception it is handed, and over the bus that exception
 | is the wrapper. Copying the code, status, category and severity but not these two left a refused command's
 | `requiredAuthorities` (and a rule's own `field`, `allowed` or title) on the cause, where nothing rendering
 | the wrapper ever looked: the same refusal said less through a command than through a controller.
 */
it('carries the cause extension members and title through both wrappers', function (string $class, string $subject) {
    $cause = (new ResourceNotFoundException('no such room', 'ROOM_NOT_FOUND'))
        ->withExtensions(['field' => 'roomId', 'requiredAuthorities' => ['ROLE_ADMIN']])
        ->withTitle('Room not found');
    /** @var CqrsException $wrapped */
    $wrapped = new $class($subject, $cause);

    expect($wrapped->extensions())->toBe(['field' => 'roomId', 'requiredAuthorities' => ['ROLE_ADMIN']])
        ->and($wrapped->title())->toBe('Room not found');
})->with([
    'command' => [CommandProcessingException::class, 'App\IngestMessage'],
    'query' => [QueryProcessingException::class, 'App\FindRoom'],
]);

it('carries no extension members and no title of its own when the cause has none, or is not a FireflyException', function (string $class, Throwable $cause) {
    /** @var CqrsException $wrapped */
    $wrapped = new $class('App\Subject', $cause);

    expect($wrapped->extensions())->toBe([])
        ->and($wrapped->title())->toBeNull();
})->with([
    'command, plain cause' => [CommandProcessingException::class, new RuntimeException('boom')],
    'query, plain cause' => [QueryProcessingException::class, new RuntimeException('boom')],
    'command, bare FireflyException' => [CommandProcessingException::class, new ResourceNotFoundException('gone')],
]);

/*
 | The MESSAGE names the message class and quotes the cause — that is the log's line. The DETAIL is what the
 | problem document publishes, and it is the cause's own: its sentence when it is a FireflyException, and
 | nothing (null — the web layer's opaque sentence) when it is not.
 */
it('publishes an authored cause\'s own detail and keeps the message class in the message alone', function (string $wrapper) {
    $cause = new ResourceNotFoundException('Order 42 does not exist.');
    /** @var FireflyException $wrapped */
    $wrapped = new $wrapper('App\Order', $cause);

    expect($wrapped->detail())->toBe('Order 42 does not exist.')
        ->and($wrapped->getMessage())->toContain('App\Order')
        ->and($wrapped->getMessage())->toEndWith('failed: Order 42 does not exist.');
})->with([CommandProcessingException::class, QueryProcessingException::class]);

it('withholds the detail when the cause is not a FireflyException', function (string $wrapper) {
    $cause = new RuntimeException('SQLSTATE[HY000] (SQL: select * from users where token = tok)');
    /** @var FireflyException $wrapped */
    $wrapped = new $wrapper('App\Order', $cause);

    expect($wrapped->detail())->toBeNull()
        ->and($wrapped->getMessage())->toContain('select * from users');
})->with([CommandProcessingException::class, QueryProcessingException::class]);

it('carries a cause\'s own replaced or withheld detail through, so a wrapper of a wrapper says what the innermost may', function () {
    $inner = new QueryProcessingException('App\FindOrder', new RuntimeException('select secret'));
    $outer = new CommandProcessingException('App\PlaceOrder', $inner);
    $authored = new CommandProcessingException('App\PlaceOrder', (new FireflyException('raw', 'X', 409))->withDetail('Said for the client.'));

    expect($outer->detail())->toBeNull()
        ->and($authored->detail())->toBe('Said for the client.');
});
