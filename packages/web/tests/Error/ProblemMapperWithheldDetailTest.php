<?php

declare(strict_types=1);

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\Business\ConflictException;
use Firefly\Kernel\Exception\FireflyException;
use Firefly\Web\Error\ProblemMapper;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Illuminate\Http\Request;

/*
 | A FireflyException whose detail() is null carries no sentence written for a client — CQRS's processing
 | wrappers say so when the cause is not a FireflyException. ProblemMapper treats it like an unhandled
 | throwable for the sentence ONLY, and keeps everything else the wrapper says about the fault.
 */

/** What a bus wrapper of a non-Firefly cause looks like to the web layer, without depending on firefly/cqrs. */
function withheldWrapper(Throwable $cause, string $code = 'COMMAND_PROCESSING_ERROR'): FireflyException
{
    return (new FireflyException("Processing command [App\\Withdraw] failed: {$cause->getMessage()}", $code, 500, ErrorCategory::Internal, ErrorSeverity::Error, $cause))
        ->withDetail(null);
}

it('answers a withheld detail with the opaque sentence, naming the reference when there is one', function () {
    $wrapper = withheldWrapper(new RuntimeException('select * from users where token = tok'));

    $plain = ProblemMapper::toFireflyException($wrapper, disclose: false);
    $referenced = ProblemMapper::toFireflyException($wrapper, disclose: false, reference: 'ref-1');

    expect($plain->detail())->toBe(ProblemMapper::OPAQUE)
        ->and($referenced->detail())->toBe(sprintf(ProblemMapper::OPAQUE_WITH_REFERENCE, 'ref-1'))
        // The wrapper is `previous`, so the log keeps the class name and the cause's message.
        ->and($plain->getPrevious())->toBe($wrapper);
});

it('keeps the wrapper\'s code, status, category, severity, title and extension members', function () {
    $wrapper = (new FireflyException('Processing query [App\FindOrder] failed: boom', 'QUERY_PROCESSING_ERROR', 503, ErrorCategory::Infrastructure, ErrorSeverity::Critical, new RuntimeException('boom')))
        ->withExtensions(['retryable' => true])
        ->withTitle('Ledger offline')
        ->withDetail(null);

    $mapped = ProblemMapper::toFireflyException($wrapper, disclose: false);

    expect($mapped->errorCode())->toBe('QUERY_PROCESSING_ERROR')
        ->and($mapped->httpStatus())->toBe(503)
        ->and($mapped->category())->toBe(ErrorCategory::Infrastructure)
        ->and($mapped->severity())->toBe(ErrorSeverity::Critical)
        ->and($mapped->extensions())->toBe(['retryable' => true])
        ->and($mapped->title())->toBe('Ledger offline');
});

it('publishes the innermost cause\'s own message, without any wrapper\'s prefix, when the deployment discloses', function () {
    $cause = new RuntimeException('select secret');
    $nested = withheldWrapper(withheldWrapper($cause, 'QUERY_PROCESSING_ERROR'));

    expect(ProblemMapper::toFireflyException($nested, disclose: true)->detail())->toBe('select secret')
        ->and(ProblemMapper::toFireflyException($nested, disclose: false)->detail())->toBe(ProblemMapper::OPAQUE);
});

it('falls back to the opaque sentence when the disclosed cause has nothing to say', function () {
    expect(ProblemMapper::toFireflyException(withheldWrapper(new RuntimeException('')), disclose: true)->detail())
        ->toBe(ProblemMapper::OPAQUE);
});

it('puts a wrapper\'s published detail on the HTML page, never its message', function () {
    $wrapper = (new ConflictException('Processing command [App\Withdraw] failed: The wallet cannot be overdrawn.'))
        ->withDetail('The wallet cannot be overdrawn.');

    expect(ProblemMapper::authoredDetail($wrapper))->toBe('The wallet cannot be overdrawn.');
});

it('renders a withheld detail through the problem renderer exactly as it renders an unhandled throwable', function () {
    $request = Request::create('/api/x', 'POST');
    $request->headers->set('X-Correlation-Id', 'corr-7');

    /** @var array<string, mixed> $fromWrapper */
    $fromWrapper = json_decode((string) (new ProblemDetailsRenderer)->render(withheldWrapper(new RuntimeException('select secret')), $request)->getContent(), true);
    /** @var array<string, mixed> $fromThrowable */
    $fromThrowable = json_decode((string) (new ProblemDetailsRenderer)->render(new RuntimeException('select secret'), $request)->getContent(), true);

    expect($fromWrapper['detail'] ?? null)->toBe(sprintf(ProblemMapper::OPAQUE_WITH_REFERENCE, 'corr-7'))
        ->and($fromWrapper['detail'] ?? null)->toBe($fromThrowable['detail'] ?? null)
        ->and($fromWrapper['code'] ?? null)->toBe('COMMAND_PROCESSING_ERROR');
});
