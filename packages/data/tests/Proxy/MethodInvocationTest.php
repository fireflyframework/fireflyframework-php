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
