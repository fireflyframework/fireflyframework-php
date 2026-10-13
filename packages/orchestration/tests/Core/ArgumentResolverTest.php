<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Argument\ArgumentResolver;
use Firefly\Orchestration\Core\Argument\ParameterBinding;
use Firefly\Orchestration\Core\ClosureInvocation;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\InvocationRunner;
use Firefly\Orchestration\Core\MethodInvocation;
use Firefly\Orchestration\Tests\Fixtures\Arguments\ArgumentProbe;
use Illuminate\Container\Container;

function orchestrationArgumentContext(): ExecutionContext
{
    $context = ExecutionContext::start(ExecutionPattern::Saga, 'order', ['amount' => 40], ['tenant' => 'acme'], 'c-1', new DateTimeImmutable);
    $context->putResult('reserve', ['hold' => 'h-1']);
    $context->putCompensationResult('reserve', 'released');
    $context->putCompensationError('reserve', new RuntimeException('undo failed'));
    $context->putTryResult('debit', 'try-1');
    $context->setVariable('tier', 'gold');

    return $context;
}

it('delivers every binding kind by name, and leaves a defaulted parameter to its default', function (): void {
    $container = new Container;
    $container->singleton(ArgumentProbe::class);
    $runner = new InvocationRunner($container, new ArgumentResolver);

    $invocation = new MethodInvocation(ArgumentProbe::class, 'everything', [
        new ParameterBinding('implicit', ParameterBinding::IMPLICIT),
        new ParameterBinding('context', ParameterBinding::CONTEXT),
        new ParameterBinding('whole', ParameterBinding::INPUT),
        new ParameterBinding('amount', ParameterBinding::INPUT_KEY, 'amount'),
        new ParameterBinding('reserved', ParameterBinding::FROM_STEP, 'reserve'),
        new ParameterBinding('undo', ParameterBinding::FROM_COMPENSATION_RESULT, 'reserve'),
        new ParameterBinding('undoError', ParameterBinding::COMPENSATION_ERROR, 'reserve'),
        new ParameterBinding('hold', ParameterBinding::FROM_TRY, 'debit'),
        new ParameterBinding('tenant', ParameterBinding::HEADER, 'tenant'),
        new ParameterBinding('headers', ParameterBinding::HEADERS),
        new ParameterBinding('tier', ParameterBinding::VARIABLE, 'tier'),
        new ParameterBinding('variables', ParameterBinding::VARIABLES),
        new ParameterBinding('correlationId', ParameterBinding::CORRELATION_ID),
        new ParameterBinding('optional', ParameterBinding::DEFAULT),
    ], variable: 'probeResult');

    $context = orchestrationArgumentContext();
    $result = $runner->run($invocation, $context, ['amount' => 40], 'the-implicit');
    $probe = $container->make(ArgumentProbe::class);

    // The probe records the variables BEFORE #[SetVariable] stores its result, so it saw only `tier`.
    expect($result)->toBe('probed')
        ->and($context->variable('probeResult'))->toBe('probed')
        ->and($probe->seen)->toBe([
            'implicit' => 'the-implicit', 'context' => 'c-1', 'whole' => ['amount' => 40], 'amount' => 40,
            'reserved' => ['hold' => 'h-1'], 'undo' => 'released', 'undoError' => 'undo failed', 'hold' => 'try-1',
            'tenant' => 'acme', 'headers' => ['tenant' => 'acme'], 'tier' => 'gold', 'variables' => ['tier' => 'gold'],
            'correlationId' => 'c-1', 'optional' => 'default',
        ]);
});

it('refuses a #[Required] parameter that resolved to null, naming the method', function (): void {
    $runner = new InvocationRunner(new Container, new ArgumentResolver);
    $invocation = new MethodInvocation(ArgumentProbe::class, 'required', [new ParameterBinding('value', ParameterBinding::FROM_STEP, 'missing', required: true)]);

    $runner->run($invocation, orchestrationArgumentContext(), [], null);
})->throws(OrchestrationException::class, 'Required parameter $value of '.ArgumentProbe::class.'::required resolved to null.');

it('hands a closure the implicit value and the context', function (): void {
    $runner = new InvocationRunner(new Container, new ArgumentResolver);
    $invocation = new ClosureInvocation(static fn (string $implicit, ExecutionContext $context): string => $implicit.'@'.$context->executionName);

    expect($runner->run($invocation, orchestrationArgumentContext(), null, 'x'))->toBe('x@order');
});

it('round-trips a method invocation and refuses to compile a closure', function (): void {
    $invocation = new MethodInvocation(ArgumentProbe::class, 'everything', [new ParameterBinding('implicit', ParameterBinding::IMPLICIT, required: true)], 'v');

    expect(MethodInvocation::fromArray($invocation->toArray()))->toEqual($invocation)
        ->and(fn () => (new ClosureInvocation(static fn (): null => null))->toArray())->toThrow(LogicException::class, 'cannot be compiled');
});
