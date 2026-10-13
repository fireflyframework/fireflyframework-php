<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Actuator;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Dlq\DeadLetterEntry;
use Firefly\Orchestration\Dlq\DeadLetterService;
use Firefly\Orchestration\Event\EventGateway;
use Firefly\Orchestration\OrchestrationSettings;
use Firefly\Orchestration\Persistence\ExecutionCriteria;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Saga\Registry\SagaRegistry;
use Firefly\Orchestration\Tcc\Registry\TccRegistry;
use Firefly\Orchestration\Workflow\Registry\WorkflowRegistry;

/**
 * `/actuator/orchestration` (unexposed until named in firefly.management.endpoints.web.exposure.include): the
 * definitions this process registered — with their layers, so nobody has to guess what runs before what —
 * execution counts by status, the dead-letter queue, and the trigger table; `executions` lists and shows
 * checkpoints, `dlq` lists dead letters. Read-only: starting, signalling and retrying belong to the REST
 * surface and the commands. The `notes` say out loud what the parity members do not do.
 */
#[Component]
final class OrchestrationEndpoint implements ActuatorEndpoint
{
    public function __construct(private readonly FireflyContainer $beans) {}

    public function endpointId(): string
    {
        return 'orchestration';
    }

    public function enabled(): bool
    {
        return $this->beans->has(OrchestrationSettings::class);
    }

    public function handle(EndpointRequest $request): ?EndpointResponse
    {
        if ($request->method !== 'GET' || ! $this->enabled()) {
            return null;
        }

        return match ($request->subPath[0] ?? null) {
            null => EndpointResponse::json($this->overview()),
            'executions' => $this->executions($request),
            'dlq' => EndpointResponse::json(['deadLetters' => array_map(static fn (DeadLetterEntry $entry): array => $entry->toArray(), $this->deadLetters()->all(self::limit($request)))]),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function overview(): array
    {
        $settings = $this->get(OrchestrationSettings::class);

        $sagas = [];
        foreach ($this->get(SagaRegistry::class)->all() as $saga) {
            $sagas[$saga->name] = ['steps' => $saga->stepIds(), 'layers' => $saga->layers, 'triggerEventType' => $saga->triggerEventType, 'layerConcurrency' => $saga->layerConcurrency];
        }
        $tccs = [];
        foreach ($this->get(TccRegistry::class)->all() as $tcc) {
            $tccs[$tcc->name] = ['participants' => $tcc->participantIds(), 'triggerEventType' => $tcc->triggerEventType];
        }
        $workflows = [];
        foreach ($this->get(WorkflowRegistry::class)->all() as $workflow) {
            $workflows[$workflow->id] = [
                'steps' => $workflow->stepIds(), 'layers' => $workflow->layers, 'triggerMode' => $workflow->triggerMode->value,
                'triggerEventType' => $workflow->triggerEventType, 'queries' => array_map('strval', array_keys($workflow->queries)),
            ];
        }

        return [
            'persistence' => $settings->persistence->provider,
            'compensationPolicy' => $settings->saga->compensationPolicy->value,
            'executions' => $this->get(ExecutionPersistenceProvider::class)->countByStatus(),
            'deadLetters' => $this->deadLetters()->count(),
            'sagas' => $sagas,
            'tccs' => $tccs,
            'workflows' => $workflows,
            'triggers' => $this->get(EventGateway::class)->describe(),
            'notes' => ['Steps of one topology layer run one after another; layerConcurrency, cpuBound and a workflow step\'s async are accepted for parity and have no effect.'],
        ];
    }

    private function executions(EndpointRequest $request): EndpointResponse
    {
        $persistence = $this->get(ExecutionPersistenceProvider::class);
        $id = $request->subPath[1] ?? null;

        if ($id !== null) {
            $state = $persistence->findById($id);

            return $state === null
                ? EndpointResponse::json(['error' => "No orchestration execution [{$id}] is persisted."], 404)
                : EndpointResponse::json($state->toArray());
        }

        $query = $request->query;
        $criteria = new ExecutionCriteria(
            pattern: is_string($query['pattern'] ?? null) ? ExecutionPattern::tryFrom(strtoupper($query['pattern'])) : null,
            status: is_string($query['status'] ?? null) ? ExecutionStatus::tryFrom(strtoupper($query['status'])) : null,
            name: is_string($query['name'] ?? null) && $query['name'] !== '' ? $query['name'] : null,
            limit: self::limit($request),
        );

        return EndpointResponse::json(['executions' => array_map(static fn (ExecutionState $state): array => [
            'correlationId' => $state->correlationId,
            'name' => $state->executionName,
            'pattern' => $state->pattern->value,
            'status' => $state->status->value,
            'updatedAt' => ExecutionState::instant($state->updatedAt),
            'waitingOn' => $state->waitingOn,
            'failureReason' => $state->failureReason,
        ], $persistence->find($criteria))]);
    }

    private function deadLetters(): DeadLetterService
    {
        return $this->get(DeadLetterService::class);
    }

    private static function limit(EndpointRequest $request): int
    {
        $limit = $request->query['limit'] ?? 50;

        return is_numeric($limit) ? max(1, min(500, (int) $limit)) : 50;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function get(string $class): object
    {
        /** @var T */
        return $this->beans->get($class);
    }
}
