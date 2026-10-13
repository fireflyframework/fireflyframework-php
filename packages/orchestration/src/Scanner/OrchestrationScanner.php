<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Scanner;

use Firefly\Context\Scan\AppScan;
use Firefly\Orchestration\Core\Argument\CompensationError;
use Firefly\Orchestration\Core\Argument\CorrelationId;
use Firefly\Orchestration\Core\Argument\FromCompensationResult;
use Firefly\Orchestration\Core\Argument\FromStep;
use Firefly\Orchestration\Core\Argument\Header;
use Firefly\Orchestration\Core\Argument\Headers;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Core\Argument\ParameterBinding;
use Firefly\Orchestration\Core\Argument\Required;
use Firefly\Orchestration\Core\Argument\SetVariable;
use Firefly\Orchestration\Core\Argument\Variable;
use Firefly\Orchestration\Core\Argument\Variables;
use Firefly\Orchestration\Core\Callback\CallbackDefinition;
use Firefly\Orchestration\Core\Condition\ConditionEvaluator;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\MethodInvocation;
use Firefly\Orchestration\Core\StepOptions;
use Firefly\Orchestration\Manifest\OrchestrationManifest;
use Firefly\Orchestration\Manifest\ScheduledExecution;
use Firefly\Orchestration\Saga\Attributes\CompensationSagaStep;
use Firefly\Orchestration\Saga\Attributes\ExternalSagaStep;
use Firefly\Orchestration\Saga\Attributes\OnSagaComplete;
use Firefly\Orchestration\Saga\Attributes\OnSagaError;
use Firefly\Orchestration\Saga\Attributes\Saga;
use Firefly\Orchestration\Saga\Attributes\SagaStep;
use Firefly\Orchestration\Saga\Attributes\ScheduledSaga;
use Firefly\Orchestration\Saga\Attributes\StepEvent;
use Firefly\Orchestration\Saga\Registry\SagaDefinition;
use Firefly\Orchestration\Saga\Registry\SagaStepDefinition;
use Firefly\Orchestration\Saga\Registry\StepEventDefinition;
use Firefly\Orchestration\Tcc\Attributes\CancelMethod;
use Firefly\Orchestration\Tcc\Attributes\ConfirmMethod;
use Firefly\Orchestration\Tcc\Attributes\FromTry;
use Firefly\Orchestration\Tcc\Attributes\OnTccComplete;
use Firefly\Orchestration\Tcc\Attributes\OnTccError;
use Firefly\Orchestration\Tcc\Attributes\ScheduledTcc;
use Firefly\Orchestration\Tcc\Attributes\Tcc;
use Firefly\Orchestration\Tcc\Attributes\TccEvent;
use Firefly\Orchestration\Tcc\Attributes\TccParticipant;
use Firefly\Orchestration\Tcc\Attributes\TryMethod;
use Firefly\Orchestration\Tcc\Registry\TccDefinition;
use Firefly\Orchestration\Tcc\Registry\TccParticipantDefinition;
use Firefly\Orchestration\Tcc\Registry\TccPhaseDefinition;
use Firefly\Orchestration\Workflow\Attributes\ChildWorkflow;
use Firefly\Orchestration\Workflow\Attributes\CompensationStep;
use Firefly\Orchestration\Workflow\Attributes\OnStepComplete;
use Firefly\Orchestration\Workflow\Attributes\OnWorkflowComplete;
use Firefly\Orchestration\Workflow\Attributes\OnWorkflowError;
use Firefly\Orchestration\Workflow\Attributes\ScheduledWorkflow;
use Firefly\Orchestration\Workflow\Attributes\WaitForAll;
use Firefly\Orchestration\Workflow\Attributes\WaitForAny;
use Firefly\Orchestration\Workflow\Attributes\WaitForSignal;
use Firefly\Orchestration\Workflow\Attributes\WaitForTimer;
use Firefly\Orchestration\Workflow\Attributes\Workflow;
use Firefly\Orchestration\Workflow\Attributes\WorkflowQuery;
use Firefly\Orchestration\Workflow\Attributes\WorkflowStep;
use Firefly\Orchestration\Workflow\Registry\ChildWorkflowDefinition;
use Firefly\Orchestration\Workflow\Registry\SignalWait;
use Firefly\Orchestration\Workflow\Registry\TimerWait;
use Firefly\Orchestration\Workflow\Registry\WaitDefinition;
use Firefly\Orchestration\Workflow\Registry\WorkflowDefinition;
use Firefly\Orchestration\Workflow\Registry\WorkflowQueryDefinition;
use Firefly\Orchestration\Workflow\Registry\WorkflowStepDefinition;
use JsonException;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * The ONE reflection file in packages/orchestration/src (ReflectionFreeOrchestrationTest). It walks the
 * application's PSR-4 roots at firefly:cache time — or in-process when there is no compiled artefact — and
 * turns every #[Saga], #[ExternalSagaStep], #[CompensationSagaStep], #[Tcc], #[TccParticipant], #[Workflow]
 * and #[Scheduled*] into definitions: method invocations with compiled parameter plans, callbacks with their
 * parameter kinds, validated topologies, linted conditions. Every refusal names the class and method, and
 * happens HERE, before an application can boot with it.
 */
final class OrchestrationScanner
{
    private readonly ConditionEvaluator $conditions;

    public function __construct(?ConditionEvaluator $conditions = null)
    {
        $this->conditions = $conditions ?? new ConditionEvaluator;
    }

    /**
     * @param  array<string, string>  $psr4  namespace prefix => absolute directory
     */
    public function scan(array $psr4): OrchestrationManifest
    {
        $classes = AppScan::classes($psr4);
        sort($classes);

        /** @var array<string, array{0: ReflectionClass<object>, 1: Saga}> $sagas */
        $sagas = [];
        /** @var array<string, list<array{0: ReflectionClass<object>, 1: ReflectionMethod, 2: ExternalSagaStep}>> $external */
        $external = [];
        /** @var array<string, array<string, array{0: ReflectionClass<object>, 1: ReflectionMethod}>> $compensations */
        $compensations = [];
        /** @var array<string, array{0: ReflectionClass<object>, 1: Tcc}> $tccs */
        $tccs = [];
        /** @var array<string, list<array{0: ReflectionClass<object>, 1: TccParticipant}>> $participants */
        $participants = [];
        /** @var array<string, WorkflowDefinition> $workflows */
        $workflows = [];
        /** @var array<string, list<ScheduledExecution>> $schedules */
        $schedules = [ExecutionPattern::Saga->value => [], ExecutionPattern::Tcc->value => [], ExecutionPattern::Workflow->value => []];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait() || $reflection->isEnum()) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(ExternalSagaStep::class) as $attribute) {
                    $step = $attribute->newInstance();
                    $external[$step->saga][] = [$reflection, $method, $step];
                }
                foreach ($method->getAttributes(CompensationSagaStep::class) as $attribute) {
                    $compensation = $attribute->newInstance();
                    if (isset($compensations[$compensation->saga][$compensation->forStepId])) {
                        throw new TopologyValidationException("Saga [{$compensation->saga}] step [{$compensation->forStepId}] has two #[CompensationSagaStep] methods; a step has one compensation.");
                    }
                    $compensations[$compensation->saga][$compensation->forStepId] = [$reflection, $method];
                }
            }

            $saga = self::classAttribute($reflection, Saga::class);
            if ($saga !== null) {
                if (isset($sagas[$saga->name])) {
                    throw new TopologyValidationException("Saga [{$saga->name}] is declared by {$sagas[$saga->name][0]->getName()} and by {$class}.");
                }
                $sagas[$saga->name] = [$reflection, $saga];
                foreach ($reflection->getAttributes(ScheduledSaga::class) as $attribute) {
                    $schedule = $attribute->newInstance();
                    $this->addSchedule($schedules, ExecutionPattern::Saga, $saga->name, $class, $schedule->cron, $schedule->zone, $schedule->enabled, $schedule->fixedDelay, $schedule->fixedRate, $schedule->initialDelay, $schedule->input, $schedule->description);
                }
            }

            $tcc = self::classAttribute($reflection, Tcc::class);
            if ($tcc !== null) {
                if (isset($tccs[$tcc->name])) {
                    throw new TopologyValidationException("TCC [{$tcc->name}] is declared by {$tccs[$tcc->name][0]->getName()} and by {$class}.");
                }
                $tccs[$tcc->name] = [$reflection, $tcc];
                foreach ($reflection->getAttributes(ScheduledTcc::class) as $attribute) {
                    $schedule = $attribute->newInstance();
                    $this->addSchedule($schedules, ExecutionPattern::Tcc, $tcc->name, $class, $schedule->cron, $schedule->zone, $schedule->enabled, $schedule->fixedDelay, $schedule->fixedRate, $schedule->initialDelay, $schedule->input, $schedule->description);
                }
            }

            $participant = self::classAttribute($reflection, TccParticipant::class);
            if ($participant !== null) {
                $participants[$participant->tcc][] = [$reflection, $participant];
            }

            $workflow = self::classAttribute($reflection, Workflow::class);
            if ($workflow !== null) {
                $definition = $this->workflow($reflection, $workflow);
                if (isset($workflows[$definition->id])) {
                    throw new TopologyValidationException("Workflow [{$definition->id}] is declared twice ({$class}).");
                }
                $workflows[$definition->id] = $definition;
                foreach ($reflection->getAttributes(ScheduledWorkflow::class) as $attribute) {
                    $schedule = $attribute->newInstance();
                    $this->addSchedule($schedules, ExecutionPattern::Workflow, $definition->id, $class, $schedule->cron, $schedule->zone, $schedule->enabled, $schedule->fixedDelay, $schedule->fixedRate, $schedule->initialDelay, $schedule->input, $schedule->description);
                }
            }
        }

        foreach ($external as $sagaName => $steps) {
            if (! isset($sagas[$sagaName])) {
                throw new TopologyValidationException("#[ExternalSagaStep] {$steps[0][0]->getName()}::{$steps[0][1]->getName()}() joins saga [{$sagaName}], but no #[Saga(name: '{$sagaName}')] class exists.");
            }
        }
        foreach ($compensations as $sagaName => $byStep) {
            if (! isset($sagas[$sagaName])) {
                throw new TopologyValidationException("#[CompensationSagaStep] for saga [{$sagaName}] names no #[Saga(name: '{$sagaName}')] class.");
            }
        }
        foreach ($participants as $tccName => $members) {
            if (! isset($tccs[$tccName])) {
                throw new TopologyValidationException("#[TccParticipant] {$members[0][0]->getName()} joins TCC [{$tccName}], but no #[Tcc(name: '{$tccName}')] class exists.");
            }
        }

        $sagaDefinitions = [];
        foreach ($sagas as $name => [$reflection, $saga]) {
            $sagaDefinitions[] = $this->saga($reflection, $saga, $external[$name] ?? [], $compensations[$name] ?? []);
        }
        usort($sagaDefinitions, static fn (SagaDefinition $a, SagaDefinition $b): int => $a->name <=> $b->name);

        $tccDefinitions = [];
        foreach ($tccs as $name => [$reflection, $tcc]) {
            $tccDefinitions[] = $this->tcc($reflection, $tcc, $participants[$name] ?? []);
        }
        usort($tccDefinitions, static fn (TccDefinition $a, TccDefinition $b): int => $a->name <=> $b->name);

        $workflowDefinitions = array_values($workflows);
        usort($workflowDefinitions, static fn (WorkflowDefinition $a, WorkflowDefinition $b): int => $a->id <=> $b->id);

        $ordered = [];
        foreach ($schedules as $group) {
            usort($group, static fn (ScheduledExecution $a, ScheduledExecution $b): int => $a->name <=> $b->name);
            $ordered = [...$ordered, ...$group];
        }

        return OrchestrationManifest::fromDefinitions($sagaDefinitions, $tccDefinitions, $workflowDefinitions, $ordered);
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @param  list<array{0: ReflectionClass<object>, 1: ReflectionMethod, 2: ExternalSagaStep}>  $external
     * @param  array<string, array{0: ReflectionClass<object>, 1: ReflectionMethod}>  $compensations
     */
    private function saga(ReflectionClass $class, Saga $saga, array $external, array $compensations): SagaDefinition
    {
        /** @var array<string, array{0: ReflectionClass<object>, 1: ReflectionMethod, 2: SagaStep|ExternalSagaStep}> $declared */
        $declared = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(SagaStep::class) as $attribute) {
                $step = $attribute->newInstance();
                if (isset($declared[$step->id])) {
                    throw new TopologyValidationException("Saga [{$saga->name}] declares step [{$step->id}] twice.");
                }
                $declared[$step->id] = [$class, $method, $step];
            }
        }
        foreach ($external as [$owner, $method, $step]) {
            if (isset($declared[$step->id])) {
                throw new TopologyValidationException("Saga [{$saga->name}] declares step [{$step->id}] twice (#[ExternalSagaStep] on {$owner->getName()}).");
            }
            $declared[$step->id] = [$owner, $method, $step];
        }

        $steps = [];
        foreach ($declared as $id => [$owner, $method, $step]) {
            $id = (string) $id;
            $where = "Saga [{$saga->name}] step [{$id}]";

            $compensation = null;
            if ($step->compensate !== '') {
                $compensation = $this->invocation($owner, $this->namedMethod($owner, $step->compensate, "{$where} names compensate: '{$step->compensate}', but"), "{$where} compensation");
            }
            if (isset($compensations[$id])) {
                if ($compensation !== null) {
                    throw new TopologyValidationException("{$where} declares compensate: and a #[CompensationSagaStep]; a step has one compensation.");
                }
                $compensation = $this->invocation($compensations[$id][0], $compensations[$id][1], "{$where} compensation");
            }

            $event = self::methodAttribute($method, StepEvent::class);
            $steps[$id] = new SagaStepDefinition(
                id: $id,
                invocation: $this->invocation($owner, $method, $where),
                dependsOn: $step->dependsOn,
                options: StepOptions::of($step->retry, $step->backoffMs, $step->timeoutMs, $step->jitter, $step->jitterFactor, $step->idempotencyKey),
                compensation: $compensation,
                compensationRetry: $step->compensationRetry,
                compensationBackoffMs: $step->compensationBackoffMs,
                compensationTimeoutMs: $step->compensationTimeoutMs,
                compensationCritical: $step->compensationCritical,
                cpuBound: $step->cpuBound,
                event: $event === null ? null : new StepEventDefinition($event->topic, $event->type, $event->key),
            );
        }

        foreach (array_keys($compensations) as $stepId) {
            if (! isset($steps[(string) $stepId])) {
                throw new TopologyValidationException("#[CompensationSagaStep] for saga [{$saga->name}] names step [{$stepId}], which the saga does not declare.");
            }
        }

        return new SagaDefinition($saga->name, $steps, $saga->layerConcurrency, $saga->triggerEventType, $this->callbacks($class, OnSagaComplete::class), $this->callbacks($class, OnSagaError::class));
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @param  list<array{0: ReflectionClass<object>, 1: TccParticipant}>  $participants
     */
    private function tcc(ReflectionClass $class, Tcc $tcc, array $participants): TccDefinition
    {
        $definitions = [];
        foreach ($participants as [$participantClass, $participant]) {
            $where = "TCC [{$tcc->name}] participant [{$participant->id}] ({$participantClass->getName()})";
            if (isset($definitions[$participant->id])) {
                throw new TopologyValidationException("{$where} is declared twice.");
            }

            /** @var array{try: list<array{0: ReflectionMethod, 1: TryMethod}>, confirm: list<array{0: ReflectionMethod, 1: ConfirmMethod}>, cancel: list<array{0: ReflectionMethod, 1: CancelMethod}>} $phases */
            $phases = ['try' => [], 'confirm' => [], 'cancel' => []];
            foreach ($participantClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(TryMethod::class) as $attribute) {
                    $phases['try'][] = [$method, $attribute->newInstance()];
                }
                foreach ($method->getAttributes(ConfirmMethod::class) as $attribute) {
                    $phases['confirm'][] = [$method, $attribute->newInstance()];
                }
                foreach ($method->getAttributes(CancelMethod::class) as $attribute) {
                    $phases['cancel'][] = [$method, $attribute->newInstance()];
                }
            }
            if (count($phases['try']) !== 1 || count($phases['confirm']) !== 1 || count($phases['cancel']) !== 1) {
                throw new TopologyValidationException("{$where} needs exactly one #[TryMethod], #[ConfirmMethod] and #[CancelMethod]; it declares ".count($phases['try']).' / '.count($phases['confirm']).' / '.count($phases['cancel']).'.');
            }

            [$tryMethod, $try] = $phases['try'][0];
            [$confirmMethod, $confirm] = $phases['confirm'][0];
            [$cancelMethod, $cancel] = $phases['cancel'][0];
            $event = self::classAttribute($participantClass, TccEvent::class);

            $definitions[$participant->id] = new TccParticipantDefinition(
                id: $participant->id,
                order: $participant->order,
                try: new TccPhaseDefinition($this->invocation($participantClass, $tryMethod, "{$where} try"), $try->timeoutMs, $try->retry, $try->backoffMs),
                confirm: new TccPhaseDefinition($this->invocation($participantClass, $confirmMethod, "{$where} confirm"), $confirm->timeoutMs, $confirm->retry, $confirm->backoffMs),
                cancel: new TccPhaseDefinition($this->invocation($participantClass, $cancelMethod, "{$where} cancel"), $cancel->timeoutMs, $cancel->retry, $cancel->backoffMs),
                timeoutMs: $participant->timeoutMs,
                optional: $participant->optional,
                jitter: $participant->jitter,
                jitterFactor: $participant->jitterFactor,
                event: $event === null ? null : new StepEventDefinition($event->topic, $event->eventType, $event->key),
            );
        }

        return new TccDefinition(
            $tcc->name, $definitions, $tcc->timeoutMs, $tcc->retryEnabled, $tcc->maxRetries, $tcc->backoffMs, $tcc->triggerEventType,
            $this->callbacks($class, OnTccComplete::class), $this->callbacks($class, OnTccError::class),
        );
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function workflow(ReflectionClass $class, Workflow $workflow): WorkflowDefinition
    {
        $id = $workflow->id !== '' ? $workflow->id : $class->getShortName();

        $compensations = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(CompensationStep::class) as $attribute) {
                $compensates = $attribute->newInstance()->compensates;
                if (isset($compensations[$compensates])) {
                    throw new TopologyValidationException("Workflow [{$id}] step [{$compensates}] has two #[CompensationStep] methods.");
                }
                $compensations[$compensates] = $method;
            }
        }

        $steps = [];
        $queries = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(WorkflowStep::class) as $attribute) {
                $step = $attribute->newInstance();
                $stepId = $step->id !== '' ? $step->id : $method->getName();
                $where = "Workflow [{$id}] step [{$stepId}]";
                if (isset($steps[$stepId])) {
                    throw new TopologyValidationException("{$where} is declared twice.");
                }
                if ($step->condition !== '') {
                    $this->conditions->lint($step->condition, $where);
                }

                $compensation = null;
                if ($step->compensationMethod !== '') {
                    $compensation = $this->invocation($class, $this->namedMethod($class, $step->compensationMethod, "{$where} names compensationMethod: '{$step->compensationMethod}', but"), "{$where} compensation");
                }
                if (isset($compensations[$stepId])) {
                    if ($compensation !== null) {
                        throw new TopologyValidationException("{$where} declares compensationMethod: and a #[CompensationStep]; a step has one compensation.");
                    }
                    $compensation = $this->invocation($class, $compensations[$stepId], "{$where} compensation");
                }

                $child = self::methodAttribute($method, ChildWorkflow::class);
                $steps[$stepId] = new WorkflowStepDefinition(
                    id: $stepId,
                    invocation: $this->invocation($class, $method, $where),
                    dependsOn: $step->dependsOn,
                    name: $step->name,
                    description: $step->description,
                    outputEventType: $step->outputEventType,
                    options: StepOptions::of(
                        $step->maxRetries >= 0 ? $step->maxRetries : $workflow->maxRetries,
                        $step->retryDelayMs >= 0 ? $step->retryDelayMs : $workflow->retryDelayMs,
                        $step->timeoutMs,
                    ),
                    condition: $step->condition,
                    async: $step->async,
                    compensatable: $step->compensatable || $compensation !== null,
                    compensation: $compensation,
                    wait: $this->wait($method, $stepId, $where),
                    child: $child === null ? null : new ChildWorkflowDefinition($child->workflowId, $child->waitForCompletion, $child->timeoutMs),
                );
            }

            foreach ($method->getAttributes(WorkflowQuery::class) as $attribute) {
                $query = $attribute->newInstance();
                $first = $method->getParameters()[0] ?? null;
                $type = $first?->getType();
                $queries[$query->name] = new WorkflowQueryDefinition(
                    $query->name,
                    new MethodInvocation($class->getName(), $method->getName()),
                    $type instanceof ReflectionNamedType && $type->getName() === ExecutionContext::class,
                );
            }
        }

        foreach (array_keys($compensations) as $stepId) {
            if (! isset($steps[(string) $stepId])) {
                throw new TopologyValidationException("#[CompensationStep(compensates: '{$stepId}')] in workflow [{$id}] names a step it does not declare.");
            }
        }

        return new WorkflowDefinition(
            id: $id,
            steps: $steps,
            name: $workflow->name,
            description: $workflow->description,
            version: $workflow->version,
            triggerMode: $workflow->triggerMode,
            triggerEventType: $workflow->triggerEventType,
            timeoutMs: $workflow->timeoutMs,
            maxRetries: $workflow->maxRetries,
            retryDelayMs: $workflow->retryDelayMs,
            publishEvents: $workflow->publishEvents,
            layerConcurrency: $workflow->layerConcurrency,
            queries: $queries,
            onStepComplete: $this->callbacks($class, OnStepComplete::class),
            onComplete: $this->callbacks($class, OnWorkflowComplete::class),
            onError: $this->callbacks($class, OnWorkflowError::class),
        );
    }

    private function wait(ReflectionMethod $method, string $stepId, string $where): ?WaitDefinition
    {
        $found = [];
        foreach ([WaitForSignal::class, WaitForTimer::class, WaitForAll::class, WaitForAny::class] as $type) {
            foreach ($method->getAttributes($type) as $attribute) {
                $found[] = $attribute->newInstance();
            }
        }
        if ($found === []) {
            return null;
        }
        if (count($found) > 1) {
            throw new TopologyValidationException("{$where} declares more than one wait; combine them with #[WaitForAll] or #[WaitForAny].");
        }

        $wait = $found[0];
        if ($wait instanceof WaitForSignal) {
            return WaitDefinition::signal($wait->name, $wait->timeoutMs);
        }
        if ($wait instanceof WaitForTimer) {
            return WaitDefinition::timer($wait->delayMs, $wait->timerId !== '' ? $wait->timerId : "{$stepId}:timer:0");
        }

        // Only #[WaitForAll] and #[WaitForAny] are left: both carry signals and timers, and differ in the kind.
        $signals = array_map(static fn (WaitForSignal $signal): SignalWait => new SignalWait($signal->name, $signal->timeoutMs), $wait->signals);
        $timers = [];
        foreach ($wait->timers as $index => $timer) {
            $timers[] = new TimerWait($timer->timerId !== '' ? $timer->timerId : "{$stepId}:timer:{$index}", $timer->delayMs);
        }

        return $wait instanceof WaitForAll ? WaitDefinition::all($signals, $timers) : WaitDefinition::any($signals, $timers);
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @param  class-string  $attributeClass
     * @return list<CallbackDefinition>
     */
    private function callbacks(ReflectionClass $class, string $attributeClass): array
    {
        $definitions = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes($attributeClass) as $attribute) {
                $callback = $attribute->newInstance();
                $parameters = $this->callbackParameters($method);
                $owner = $class->getName();
                $name = $method->getName();

                $definitions[] = match (true) {
                    $callback instanceof OnSagaError, $callback instanceof OnTccError => new CallbackDefinition($owner, $name, $parameters, $callback->async, $callback->priority, $callback->errorTypes, $callback->suppressError),
                    $callback instanceof OnWorkflowError => new CallbackDefinition($owner, $name, $parameters, $callback->async, $callback->priority, $callback->errorTypes, $callback->suppressError, $callback->stepIds),
                    $callback instanceof OnStepComplete => new CallbackDefinition($owner, $name, $parameters, $callback->async, $callback->priority, stepIds: $callback->stepIds),
                    $callback instanceof OnSagaComplete, $callback instanceof OnTccComplete, $callback instanceof OnWorkflowComplete => new CallbackDefinition($owner, $name, $parameters, $callback->async, $callback->priority),
                    default => throw new LogicException("Unsupported callback attribute {$attributeClass}."),
                };
            }
        }

        return $definitions;
    }

    /** @return list<string> */
    private function callbackParameters(ReflectionMethod $method): array
    {
        $kinds = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : '';
            $kinds[] = match (true) {
                $name === ExecutionContext::class => CallbackDefinition::CONTEXT,
                $name !== '' && is_a($name, Throwable::class, true) => CallbackDefinition::ERROR,
                default => CallbackDefinition::RESULT,
            };
        }

        return $kinds;
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function invocation(ReflectionClass $class, ReflectionMethod $method, string $where): MethodInvocation
    {
        return new MethodInvocation($class->getName(), $method->getName(), $this->bindings($class, $method, $where), self::methodAttribute($method, SetVariable::class)?->name);
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return list<ParameterBinding>
     */
    private function bindings(ReflectionClass $class, ReflectionMethod $method, string $where): array
    {
        $bindings = [];
        $implicit = false;

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $required = $parameter->getAttributes(Required::class) !== [];
            $binding = $this->annotated($parameter, $name, $required);

            if ($binding === null) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType && $type->getName() === ExecutionContext::class) {
                    $binding = new ParameterBinding($name, ParameterBinding::CONTEXT);
                } elseif (! $implicit) {
                    $binding = new ParameterBinding($name, ParameterBinding::IMPLICIT, required: $required);
                    $implicit = true;
                } elseif ($parameter->isDefaultValueAvailable()) {
                    $binding = new ParameterBinding($name, ParameterBinding::DEFAULT);
                } else {
                    throw new TopologyValidationException("{$where}: parameter \${$name} of {$class->getName()}::{$method->getName()}() cannot be bound — only one parameter receives the input implicitly. Annotate it with #[Input], #[FromStep], #[FromTry], #[FromCompensationResult], #[CompensationError], #[Header], #[Headers], #[Variable], #[Variables] or #[CorrelationId], or give it a default.");
                }
            }

            $bindings[] = $binding;
        }

        return $bindings;
    }

    private function annotated(ReflectionParameter $parameter, string $name, bool $required): ?ParameterBinding
    {
        foreach ($parameter->getAttributes() as $attribute) {
            if (! class_exists($attribute->getName())) {
                continue;
            }

            $instance = $attribute->newInstance();
            $binding = match (true) {
                $instance instanceof Input => new ParameterBinding($name, $instance->key === '' ? ParameterBinding::INPUT : ParameterBinding::INPUT_KEY, $instance->key, $required),
                $instance instanceof FromStep => new ParameterBinding($name, ParameterBinding::FROM_STEP, $instance->stepId, $required),
                $instance instanceof FromCompensationResult => new ParameterBinding($name, ParameterBinding::FROM_COMPENSATION_RESULT, $instance->stepId, $required),
                $instance instanceof CompensationError => new ParameterBinding($name, ParameterBinding::COMPENSATION_ERROR, $instance->stepId, $required),
                $instance instanceof FromTry => new ParameterBinding($name, ParameterBinding::FROM_TRY, $instance->participantId, $required),
                $instance instanceof Header => new ParameterBinding($name, ParameterBinding::HEADER, $instance->name, $required),
                $instance instanceof Headers => new ParameterBinding($name, ParameterBinding::HEADERS, '', $required),
                $instance instanceof Variable => new ParameterBinding($name, ParameterBinding::VARIABLE, $instance->name, $required),
                $instance instanceof Variables => new ParameterBinding($name, ParameterBinding::VARIABLES, '', $required),
                $instance instanceof CorrelationId => new ParameterBinding($name, ParameterBinding::CORRELATION_ID, '', $required),
                default => null,
            };

            if ($binding !== null) {
                return $binding;
            }
        }

        return null;
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function namedMethod(ReflectionClass $class, string $name, string $prefix): ReflectionMethod
    {
        if (! $class->hasMethod($name) || ! $class->getMethod($name)->isPublic()) {
            throw new TopologyValidationException("{$prefix} {$class->getName()} has no public method {$name}().");
        }

        return $class->getMethod($name);
    }

    /**
     * @param  array<string, list<ScheduledExecution>>  $schedules
     */
    private function addSchedule(array &$schedules, ExecutionPattern $pattern, string $name, string $class, string $cron, string $zone, bool $enabled, int $fixedDelay, int $fixedRate, int $initialDelay, string $input, string $description): void
    {
        if (! $enabled) {
            return;
        }

        $where = '#[Scheduled'.ucfirst(strtolower($pattern->value)).'] on '.$class;
        if ($initialDelay !== 0) {
            throw new TopologyValidationException("{$where} sets initialDelay: {$initialDelay}; a scheduled orchestration cannot honour an initial delay. Start it from a #[Scheduled(initialDelay: …)] method instead.");
        }
        if (count(array_filter([$cron !== '', $fixedRate > 0, $fixedDelay > 0])) !== 1) {
            throw new TopologyValidationException("{$where} needs exactly one of cron, fixedRate or fixedDelay (milliseconds, greater than zero).");
        }

        try {
            $decoded = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new TopologyValidationException("{$where} input is not JSON: {$error->getMessage()}", $error);
        }
        if (! is_array($decoded)) {
            throw new TopologyValidationException("{$where} input must be a JSON object.");
        }
        $values = [];
        foreach ($decoded as $key => $value) {
            $values[(string) $key] = $value;
        }

        $schedules[$pattern->value][] = new ScheduledExecution(
            $pattern,
            $name,
            $cron !== '' ? $cron : null,
            $zone !== '' ? $zone : null,
            $fixedRate > 0 ? "{$fixedRate}ms" : null,
            $fixedDelay > 0 ? "{$fixedDelay}ms" : null,
            $values,
            $description,
        );
    }

    /**
     * @template T of object
     *
     * @param  ReflectionClass<object>  $class
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    private static function classAttribute(ReflectionClass $class, string $attribute): ?object
    {
        $found = $class->getAttributes($attribute);

        return $found === [] ? null : $found[0]->newInstance();
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    private static function methodAttribute(ReflectionMethod $method, string $attribute): ?object
    {
        $found = $method->getAttributes($attribute);

        return $found === [] ? null : $found[0]->newInstance();
    }
}
