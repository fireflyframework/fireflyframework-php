<?php

declare(strict_types=1);

use Firefly\Data\Proxy\MethodInterceptor;
use Firefly\Data\Proxy\MethodInvocation;
use Firefly\Data\Transaction\TransactionalDescriptor;

/**
 * A recording link: notes its label before and after proceed(), and may rewrite the arguments on the way in.
 *
 * @param  list<string>  $log
 * @param  list<mixed>|null  $rewrite
 */
function chainLink(string $label, array &$log, ?array $rewrite = null): MethodInterceptor
{
    return new class($label, $log, $rewrite) implements MethodInterceptor
    {
        /**
         * @param  list<string>  $log
         * @param  list<mixed>|null  $rewrite
         */
        public function __construct(private readonly string $label, public array &$log, private readonly ?array $rewrite) {}

        public function invoke(MethodInvocation $invocation): mixed
        {
            $this->log[] = 'before:'.$this->label;
            if ($this->rewrite !== null) {
                $invocation->setArguments($this->rewrite);
            }
            $result = $invocation->proceed();
            $this->log[] = 'after:'.$this->label;

            return $result;
        }
    };
}

it('runs the interceptors outermost-first and the terminal last, threading the return value back out', function () {
    $log = [];
    $target = new stdClass;
    $invocation = new MethodInvocation(
        $target,
        stdClass::class,
        'work',
        [2, 3],
        [chainLink('outer', $log), chainLink('inner', $log)],
        [],
        static function (array $args) use (&$log): int {
            $log[] = 'terminal:'.implode(',', array_map(static fn (mixed $arg): string => var_export($arg, true), $args));

            return array_sum($args);
        },
    );

    expect($invocation->proceed())->toBe(5)
        ->and($log)->toBe(['before:outer', 'before:inner', 'terminal:2,3', 'after:inner', 'after:outer'])
        ->and($invocation->getThis())->toBe($target)
        ->and($invocation->getDeclaredClass())->toBe(stdClass::class)
        ->and($invocation->getMethod())->toBe('work');
});

it('hands the terminal the arguments an interceptor rewrote', function () {
    $log = [];
    $invocation = new MethodInvocation(new stdClass, stdClass::class, 'work', [1, 1], [chainLink('filter', $log, [10, 20])], [], static fn (array $args): int => array_sum($args));

    expect($invocation->proceed())->toBe(30)
        ->and($invocation->getArguments())->toBe([10, 20]);
});

it('answers a typed descriptor lookup and null for a class nothing was baked for', function () {
    $descriptor = new TransactionalDescriptor(readOnly: true);
    $invocation = new MethodInvocation(new stdClass, stdClass::class, 'work', [], [], [TransactionalDescriptor::class => $descriptor], static fn (array $args): null => null);

    expect($invocation->descriptor(TransactionalDescriptor::class))->toBe($descriptor)
        ->and($invocation->descriptor(stdClass::class))->toBeNull();
});

it('is single-use: a second proceed() from the same link reaches the next link, never itself again', function () {
    $calls = 0;
    $twice = new class($calls) implements MethodInterceptor
    {
        public function __construct(private int &$calls) {}

        public function invoke(MethodInvocation $invocation): mixed
        {
            $this->calls++;
            $invocation->proceed();

            return $invocation->proceed();
        }
    };
    $terminal = 0;
    $invocation = new MethodInvocation(new stdClass, stdClass::class, 'work', [], [$twice], [], static function (array $args) use (&$terminal): int {
        return ++$terminal;
    });

    expect($invocation->proceed())->toBe(2)
        ->and($calls)->toBe(1);
});

/*
 | …and the escape hatch from that rule, which is what makes #[Retry] beside #[Transactional] safe. A
 | repeating link takes an invocableClone() per repetition instead of proceeding twice, so every repetition
 | re-enters the WHOLE remainder — the inner links and the terminal — rather than skipping one inner link per
 | extra call. Written as the transaction-shaped chain the hazard actually lives in: with a bare second
 | proceed() the log below reads ['before:tx', 'terminal:1', 'after:tx', 'terminal:2'], i.e. the second run
 | of the method happened OUTSIDE the transaction.
 */
it('re-enters the whole remainder from an invocableClone(), leaving the original invocation where it stood', function () {
    $log = [];
    $repeating = new class($log) implements MethodInterceptor
    {
        /** @param  list<string>  $log */
        public function __construct(public array &$log) {}

        public function invoke(MethodInvocation $invocation): mixed
        {
            $last = null;
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $last = $invocation->invocableClone()->proceed();
            }

            return $last;
        }
    };
    $terminal = 0;
    $invocation = new MethodInvocation(new stdClass, stdClass::class, 'work', [], [$repeating, chainLink('tx', $log)], [], static function (array $args) use (&$terminal, &$log): int {
        $log[] = 'terminal:'.(++$terminal);

        return $terminal;
    });

    expect($invocation->proceed())->toBe(3)
        ->and($log)->toBe([
            'before:tx', 'terminal:1', 'after:tx',
            'before:tx', 'terminal:2', 'after:tx',
            'before:tx', 'terminal:3', 'after:tx',
        ]);
});

it('gives each invocableClone() its own arguments, so an inner rewrite never leaks back to the original', function () {
    $seen = [];
    $repeating = new class implements MethodInterceptor
    {
        public function invoke(MethodInvocation $invocation): mixed
        {
            $invocation->invocableClone()->proceed();

            return $invocation->invocableClone()->proceed();
        }
    };
    $log = [];
    $invocation = new MethodInvocation(
        new stdClass,
        stdClass::class,
        'work',
        [1],
        // An inner pre-filter: it rewrites the arguments on the way in, on whichever invocation it is handed.
        [$repeating, chainLink('filter', $log, [10, 20])],
        [],
        static function (array $args) use (&$seen): int {
            $seen[] = $args;

            return array_sum($args);
        },
    );

    expect($invocation->proceed())->toBe(30)
        ->and($seen)->toBe([[10, 20], [10, 20]])
        // The ORIGINAL never moved: the rewrite landed on the clones, so a link reading the arguments after
        // the repetitions (a #[Fallback] recovery does exactly that) still sees what the caller passed.
        ->and($invocation->getArguments())->toBe([1]);
});

it('re-enters the remainder from a clone even after a repetition threw', function () {
    $attempts = 0;
    $repeating = new class implements MethodInterceptor
    {
        public function invoke(MethodInvocation $invocation): mixed
        {
            try {
                return $invocation->invocableClone()->proceed();
            } catch (RuntimeException) {
                return $invocation->invocableClone()->proceed();
            }
        }
    };
    $log = [];
    $invocation = new MethodInvocation(new stdClass, stdClass::class, 'work', [], [$repeating, chainLink('tx', $log)], [], static function (array $args) use (&$attempts): string {
        if (++$attempts === 1) {
            throw new RuntimeException('down');
        }

        return 'ok';
    });

    // The second repetition ran the inner link again — the throwing one did not consume it.
    expect($invocation->proceed())->toBe('ok')
        ->and($log)->toBe(['before:tx', 'before:tx', 'after:tx'])
        ->and($attempts)->toBe(2);
});
