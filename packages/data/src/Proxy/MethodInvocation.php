<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

use Closure;

/**
 * The single-use call the generated proxy hands its interceptor chain (Spring's MethodInvocation).
 *
 * Everything an interceptor may want is here without reflection: the proxy instance, the DECLARED class (the
 * bean's own class, never the proxy's), the method name, the positional arguments exactly as the caller passed
 * them, and the descriptors the compile step baked for this method keyed by their class — a transactional
 * interceptor asks for its TransactionalDescriptor, a security one for its SecurityMethodDescriptor, and
 * neither knows the other exists. proceed() walks the chain outermost-first and finishes in the terminal
 * closure `fn (array $args) => parent::m(...$args)`, which is why setArguments() is honoured by the real
 * method: the terminal spreads whatever the list holds when it is finally reached.
 *
 * SINGLE-USE, with one sanctioned escape hatch: invocableClone(). proceed() advances a cursor, so a link
 * that calls it twice reaches the NEXT link the second time, never the remainder it just ran. That is the
 * right contract for a link that wraps the call once (a transaction, a security check), and the wrong one
 * for a link that must run the same remainder AGAIN — a retry. Such a link takes an invocableClone() per
 * attempt, exactly as Spring Retry does over ReflectiveMethodInvocation.
 */
final class MethodInvocation
{
    private int $cursor = 0;

    /**
     * @param  list<mixed>  $arguments  positional, as the caller passed them (PHP has already applied defaults)
     * @param  list<MethodInterceptor>  $interceptors  outermost first
     * @param  array<class-string, object>  $descriptors  the baked per-method descriptor each interceptor reads, keyed by its class
     * @param  Closure(list<mixed>): mixed  $terminal  the real method
     */
    public function __construct(
        private readonly object $proxy,
        private readonly string $declaredClass,
        private readonly string $method,
        private array $arguments,
        private readonly array $interceptors,
        private readonly array $descriptors,
        private readonly Closure $terminal,
    ) {}

    public function proceed(): mixed
    {
        $interceptor = $this->interceptors[$this->cursor++] ?? null;

        return $interceptor === null
            ? ($this->terminal)($this->arguments)
            : $interceptor->invoke($this);
    }

    /**
     * A SECOND invocation that resumes the chain exactly where this one stands — Spring's
     * ReflectiveMethodInvocation::invocableClone(), and there for the same reason Spring Retry needs it.
     *
     * A link that repeats the call cannot call proceed() twice: the cursor has already moved past the
     * remainder, so the second call runs whatever follows the link itself instead. For `#[Retry]` beside
     * `#[Transactional]` that is not a subtle ordering wrinkle but a data-integrity hazard — attempt 2
     * would run with no transaction around it, so a half-written attempt has nothing to roll back. The
     * repeating link therefore takes a clone per attempt, taken BEFORE any proceed() while the cursor
     * still points at the link that follows it, and each attempt walks the whole remainder again.
     *
     * The clone shares the proxy, the descriptors and the terminal closure (all readonly) and carries its
     * own cursor and its own argument list, so an inner link that calls setArguments() narrows THAT
     * attempt and never leaks the rewrite into the next one.
     */
    public function invocableClone(): self
    {
        return clone $this;
    }

    public function getThis(): object
    {
        return $this->proxy;
    }

    public function getDeclaredClass(): string
    {
        return $this->declaredClass;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @return list<mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * Replaces what the real method will receive. The list is re-indexed on the way in, so an interceptor that
     * filtered or unset an element still hands the terminal a positional list it can spread.
     *
     * @param  array<mixed>  $arguments
     */
    public function setArguments(array $arguments): void
    {
        $this->arguments = array_values($arguments);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    public function descriptor(string $class): ?object
    {
        $descriptor = $this->descriptors[$class] ?? null;

        return $descriptor instanceof $class ? $descriptor : null;
    }
}
