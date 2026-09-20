<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Proxy;

/**
 * The parent half of the state-copy fixture: the three shapes of parent state a proxied bean inherits and the
 * ProxyFactory's copy must reproduce from the PARENT's own scope. A `private` (invisible to a closure bound to
 * the child — the EloquentRepository::$translator shape), a `protected readonly` (initialisable from its
 * declaring class alone on PHP < 8.4 — the EloquentRepository::$manifest shape), and a `private` under the same
 * name as one the child declares (`$label`) — two distinct slots that a name-keyed copy would collapse into one.
 */
abstract class TxLedgerBase
{
    private readonly string $secret;

    private string $label = 'base';

    public function __construct(
        protected readonly ?string $manifest = null,
        ?string $secret = null,
    ) {
        $this->secret = $secret ?? 'default-secret';
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function manifest(): ?string
    {
        return $this->manifest;
    }

    public function baseLabel(): string
    {
        return $this->label;
    }
}
