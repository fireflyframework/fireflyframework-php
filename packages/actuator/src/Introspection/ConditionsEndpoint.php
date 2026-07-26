<?php

declare(strict_types=1);

namespace Firefly\Actuator\Introspection;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Lazy;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionOutcome;

/**
 * Reports the M4 ConditionEvaluationReport (the /conditions seam pre-placed for M12).
 *
 * Return type is narrowed to the non-nullable EndpointResponse (the same covariant-narrowing idiom
 * InfoEndpoint/EnvEndpoint/BeansEndpoint use): /conditions has no sub-resource concept to 404 on, so
 * handle() always produces a body — PHPStan (level max) flags the wider nullable type as dead code
 * otherwise.
 *
 * #[Lazy] is REQUIRED here, not decorative — same reasoning as BeansEndpoint, with a subtler failure
 * mode: ActuatorRouteRegistrar (WiringPasses, 1000) is the ONLY place the REAL, boot-accumulated
 * ConditionEvaluationReport (`$context->report`) is container-bound. EagerSingletonsPass (900) runs
 * first and, unlike BeansCatalog, ConditionEvaluationReport has an implicit no-arg constructor — so an
 * un-#[Lazy] resolution at phase 900 would NOT crash boot, it would silently construct and cache a
 * BRAND NEW, always-empty ConditionEvaluationReport as this singleton's dependency (captured forever
 * in the readonly-style property), and ActuatorRouteRegistrar's later `instance()` rebinding would
 * never reach an already-built ConditionsEndpoint. /actuator/conditions would then permanently report
 * zero positiveMatches/negativeMatches in production — a silent correctness bug, not a crash, which is
 * why it must be prevented the same way as BeansEndpoint's crash: excluding this component from the
 * eager pass so it is only ever built by ActuatorRouteRegistrar's own resolve, strictly after the real
 * report is bound.
 */
#[Component]
#[Lazy]
final class ConditionsEndpoint implements ActuatorEndpoint
{
    public function __construct(private readonly ConditionEvaluationReport $report) {}

    public function endpointId(): string
    {
        return 'conditions';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): EndpointResponse
    {
        return EndpointResponse::json([
            'positiveMatches' => $this->rows($this->report->matches()),
            'negativeMatches' => $this->rows($this->report->nonMatches()),
        ]);
    }

    /**
     * @param  list<array{class: string, attribute: string, outcome: ConditionOutcome}>  $entries
     * @return list<array{class: string, condition: string}>
     */
    private function rows(array $entries): array
    {
        return array_map(
            static fn (array $entry): array => ['class' => $entry['class'], 'condition' => $entry['attribute']],
            $entries,
        );
    }
}
