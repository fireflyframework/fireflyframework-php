<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Capstone;

use Firefly\Data\Transaction\Attributes\Transactional;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The SECOND wiring shape the bean-post-processor chain is installed for, proved end to end: a class with NO
 * stereotype at all, wired by the #[Bean] method on CapstoneTransactionalConfiguration because its constructor
 * takes a string the container cannot autowire.
 *
 * Until this fixture existed the claim "a #[Bean]-wired unstereotyped class IS proxied" was only ever read off
 * the source — RegisterBeanPostProcessorsPass::abstractsToExtend() threads $bean->returns through as the
 * $declaredClass, TransactionalBeanPostProcessor keys hasProxyFor() on that same declared class, and
 * ProxyFactory::wrap() reaches the proxy through newInstanceWithoutConstructor(), so the non-autowirable
 * constructor is no obstacle and the readonly private below is copied slot by slot. Every one of those four
 * links is exercised by the capstone test that drives this class, so the claim is now a test rather than a
 * chain of readings — and it is the claim observability's ObservabilityMethodScanner leans on when it declines
 * to refuse a metric attribute on this same shape.
 *
 * NOT `final` (the proxy extends it) and its methods are not `final` either (the proxy overrides them).
 */
class BeanWiredLedger
{
    public function __construct(private readonly string $label) {}

    /** Two writes and a throw: if the proxy never wrapped this bean, the first row would survive. */
    #[Transactional]
    public function recordAndFail(): void
    {
        DB::table('accounts')->insert(['name' => $this->label.'-a']);
        DB::table('accounts')->insert(['name' => $this->label.'-b']);

        throw new RuntimeException('ledger boom');
    }

    /** The commit half, which also proves the copied constructor state survived the wrap. */
    #[Transactional]
    public function recordAndCommit(): string
    {
        DB::table('accounts')->insert(['name' => $this->label]);

        return $this->label;
    }
}
