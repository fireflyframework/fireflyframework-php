<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

/**
 * How a capability package contributes advice to the proxy plan (the port firefly/security implements for
 * method security). A source is a #[Component] so the uncached boot can collect every one of them through
 * Container::getAll(); a compiler that writes proxy-plan.php names its sources explicitly. (firefly:cache does
 * not write that file yet: it still compiles transactional.php and the proxies from the transactional advice
 * alone, and the ProxyPlan bean bridges from that manifest so a cached app never scans.)
 *
 * scan() is the source's SCAN-TIME half and may reflect — inside that package's own sanctioned scanner file —
 * returning pure arrays that var_export cleanly into proxy-plan.php. render() turns one of those rows back
 * into PHP source (`new Foo(...)`, `Foo::fromArray([...])`) so the descriptor is baked into the generated
 * proxy as a literal and the runtime never looks it up. The interceptor named by advice() is resolved from
 * the container when the bean is wrapped; if that bean is absent, the proxy runs a PassThroughInterceptor in
 * its place when the advice declared itself inert when unbound (the capability is switched off by design),
 * and the boot fails with a ConfigurationException otherwise.
 */
interface AdviceSource
{
    public function advice(): Advice;

    /**
     * @param  array<string, string>  $psr4  namespace-prefix => absolute directory
     * @return array<class-string, array<string, array<string, mixed>>> class => method => descriptor row
     */
    public function scan(array $psr4): array;

    /**
     * @param  array<string, mixed>  $row
     */
    public function render(array $row): string;
}
