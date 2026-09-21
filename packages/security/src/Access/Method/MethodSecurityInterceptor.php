<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Method;

use Firefly\Config\Config;
use Firefly\Data\Proxy\MethodInterceptor;
use Firefly\Data\Proxy\MethodInvocation;

/**
 * The proxy link that enforces method security on ANY stereotyped bean (Spring's authorization method
 * interceptors, folded into one). It reads the SecurityMethodDescriptor the compile step baked for the method
 * — no manifest lookup, no reflection — runs before() (pre-filter + pre rule), writes a narrowed argument back
 * into the positional list, proceeds, and runs after() (post rule + post-filter) on the result. It sits at
 * Advice order 100, ahead of the transactional link at 1000, so a refusal never opens a transaction.
 *
 * The two flags are read LIVE on every call rather than captured at construction: the bean exists only when
 * both are on, but a test's withoutSecurity() flips them after boot and the proxy already holds this instance.
 */
final class MethodSecurityInterceptor implements MethodInterceptor
{
    public function __construct(
        private readonly MethodSecurityEvaluator $evaluator,
        private readonly Config $config,
    ) {}

    public function invoke(MethodInvocation $invocation): mixed
    {
        $rule = $invocation->descriptor(SecurityMethodDescriptor::class);
        if ($rule === null || ! $this->config->bool('firefly.security.enabled', false) || ! $this->config->bool('firefly.security.method.enabled', true)) {
            return $invocation->proceed();
        }

        $named = $this->evaluator->before($rule, $this->evaluator->bind($rule, $invocation->getArguments()));

        if ($rule->preFilter !== null) {
            $invocation->setArguments($this->positional($rule, $invocation->getArguments(), $named));
        }

        return $this->evaluator->after($rule, $named, $invocation->proceed());
    }

    /**
     * Writes the (possibly narrowed) named values back into the positional list the real method receives.
     *
     * @param  list<mixed>  $args
     * @param  array<string, mixed>  $named
     * @return list<mixed>
     */
    private function positional(SecurityMethodDescriptor $rule, array $args, array $named): array
    {
        foreach ($rule->params as $index => $name) {
            if (array_key_exists($name, $named) && array_key_exists($index, $args)) {
                $args[$index] = $named[$name];
            }
        }

        return $args;
    }
}
