<?php

declare(strict_types=1);

namespace Firefly\Context\Boot;

/**
 * Buffers BootPass contributions made before the FireflyKernel is bound. Laravel discovers package
 * providers alphabetically, so a capability provider (e.g. firefly/actuator) can register ahead of the
 * bootstrap FireflyAutoConfigureServiceProvider that binds the kernel. Buffering here lets register()
 * avoid resolving the kernel early; the FIRST booting() callback drains the buffer into the (by-then
 * bound) kernel. drain() empties the buffer, so every later drain — from every other subclass — is a
 * no-op, mirroring the kernel's own first-one-wins, idempotent-per-phase shape.
 */
final class PendingBootPasses
{
    /** @var list<BootPass> */
    private array $passes = [];

    public function add(BootPass $pass): void
    {
        $this->passes[] = $pass;
    }

    /** @return list<BootPass> */
    public function drain(): array
    {
        $drained = $this->passes;
        $this->passes = [];

        return $drained;
    }
}
