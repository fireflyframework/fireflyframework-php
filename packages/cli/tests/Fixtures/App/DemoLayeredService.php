<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\App;

use Firefly\Container\Attributes\Service;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Observability\Method\Timed;
use Firefly\Resilience\Method\Retry;
use Firefly\Security\Access\Attributes\PreAuthorize;

/**
 * The one fixture that carries ALL FOUR advice kinds on a single method, so the compiled plan has something
 * to say about their relative order. DemoTimedService, DemoSecuredService and DemoTransactionalService each
 * prove that their own AdviceSource reaches planner(); none of them can prove that metrics (50) is chained
 * OUTSIDE security (100), which is outside resilience (200), which is outside the transaction (1000),
 * because a class with one advice has no chain.
 *
 * The number 50 is the single most load-bearing constant in firefly/observability's method metrics — the
 * whole argument for #[Timed] being worth anything under a permissions misconfiguration rests on the metric
 * link running before MethodSecurityInterceptor throws — and it was, until this fixture, justified only in a
 * docblock. #[Retry] is here for the same reason and with the same history: planner() names four
 * AdviceSources and this package's suite could not tell whether the fourth was in the list at all, so
 * deleting `new ResilienceAdviceSource` left every test green while every cached application silently lost
 * all six resilience attributes. NOT final: the proxy extends it.
 */
#[Service]
class DemoLayeredService
{
    #[Timed('demo.layered')]
    #[PreAuthorize("hasRole('ADMIN')")]
    #[Retry('demo')]
    #[Transactional]
    public function all(string $id): string
    {
        return 'layered:'.$id;
    }
}
