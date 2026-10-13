<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Boot;

use Closure;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Orchestration\Core\Condition\ConditionEvaluator;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\Orchestrator;
use Firefly\Orchestration\Event\EventGateway;
use Firefly\Orchestration\Manifest\OrchestrationManifest;
use Firefly\Orchestration\OrchestrationSettings;
use Firefly\Orchestration\Saga\Registry\SagaRegistry;
use Firefly\Orchestration\Tcc\Registry\TccRegistry;
use Firefly\Orchestration\Validation\OrchestrationValidator;
use Firefly\Orchestration\Workflow\Registry\WorkflowRegistry;
use Illuminate\Container\Container;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Turns the compiled OrchestrationManifest into registered definitions at boot (WiringPasses, order 20 —
 * after the eager singletons, so the settings bean has already refused a bad configuration). Each pattern
 * whose `enabled` key is false registers nothing (spec decision D18). Then the cross-definition validation
 * runs and REFUSES THE BOOT on an error, and every definition with a `triggerEventType` registers an
 * executor on the EventGateway; the Orchestrator is resolved lazily, inside the executor, on the first event.
 */
final class OrchestrationRegistryPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 20;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;
        if (! $container->bound(OrchestrationManifest::class) || ! $container->bound(OrchestrationSettings::class)) {
            return;
        }

        /** @var OrchestrationManifest $manifest */
        $manifest = $container->make(OrchestrationManifest::class);
        /** @var OrchestrationSettings $settings */
        $settings = $container->make(OrchestrationSettings::class);
        /** @var SagaRegistry $sagas */
        $sagas = $container->make(SagaRegistry::class);
        /** @var TccRegistry $tccs */
        $tccs = $container->make(TccRegistry::class);
        /** @var WorkflowRegistry $workflows */
        $workflows = $container->make(WorkflowRegistry::class);

        if ($settings->saga->enabled) {
            foreach ($manifest->sagas() as $saga) {
                $sagas->register($saga);
            }
        }
        if ($settings->tcc->enabled) {
            foreach ($manifest->tccs() as $tcc) {
                $tccs->register($tcc);
            }
        }
        if ($settings->workflow->enabled) {
            foreach ($manifest->workflows() as $workflow) {
                $workflows->register($workflow);
            }
        }

        if ($settings->validationEnabled) {
            /** @var ConditionEvaluator $conditions */
            $conditions = $container->make(ConditionEvaluator::class);
            $logger = $container->bound(LoggerInterface::class) ? $container->make(LoggerInterface::class) : new NullLogger;
            $validator = new OrchestrationValidator($conditions);
            $validator->enforce($validator->validate($sagas, $tccs, $workflows), $settings->failOnWarning, $logger);
        }

        if (! $settings->events->gatewayEnabled) {
            return;
        }

        /** @var EventGateway $gateway */
        $gateway = $container->make(EventGateway::class);
        $start = static fn (ExecutionPattern $pattern, string $name): Closure => self::starter($container, $pattern, $name);

        foreach ($sagas->all() as $saga) {
            if ($saga->triggerEventType !== '') {
                $gateway->register($saga->triggerEventType, $saga->name, ExecutionPattern::Saga, $start(ExecutionPattern::Saga, $saga->name));
            }
        }
        foreach ($tccs->all() as $tcc) {
            if ($tcc->triggerEventType !== '') {
                $gateway->register($tcc->triggerEventType, $tcc->name, ExecutionPattern::Tcc, $start(ExecutionPattern::Tcc, $tcc->name));
            }
        }
        foreach ($workflows->all() as $workflow) {
            if ($workflow->triggerEventType !== '') {
                $gateway->register($workflow->triggerEventType, $workflow->id, ExecutionPattern::Workflow, $start(ExecutionPattern::Workflow, $workflow->id));
            }
        }
    }

    /**
     * The executor the gateway runs for one trigger: it resolves the Orchestrator only when the first event
     * arrives, so registering triggers at boot builds no engine.
     *
     * @return Closure(array<string, mixed>): mixed
     */
    private static function starter(Container $container, ExecutionPattern $pattern, string $name): Closure
    {
        return static function (array $payload) use ($container, $pattern, $name): mixed {
            $input = [];
            foreach ($payload as $key => $value) {
                $input[(string) $key] = $value;
            }
            /** @var Orchestrator $orchestrator */
            $orchestrator = $container->make(Orchestrator::class);

            return $orchestrator->start($pattern, $name, $input);
        };
    }
}
