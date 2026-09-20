<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Chain;

use Firefly\Data\Proxy\MethodInterceptor;
use Firefly\Data\Proxy\MethodInvocation;

/** Records every invocation it sees, in order, with the baked note; optionally rewrites the first argument. */
final class AuditInterceptor implements MethodInterceptor
{
    /** @var list<string> */
    public array $log = [];

    public function __construct(private readonly ?string $rewriteFirstArgumentTo = null) {}

    public function invoke(MethodInvocation $invocation): mixed
    {
        $note = $invocation->descriptor(AuditNote::class);
        $this->log[] = 'before:'.$invocation->getMethod().':'.($note === null ? '?' : $note->label);

        if ($this->rewriteFirstArgumentTo !== null && $invocation->getArguments() !== []) {
            $arguments = $invocation->getArguments();
            $arguments[0] = $this->rewriteFirstArgumentTo;
            $invocation->setArguments($arguments);
        }

        $result = $invocation->proceed();
        $this->log[] = 'after:'.$invocation->getMethod();

        return $result;
    }
}
