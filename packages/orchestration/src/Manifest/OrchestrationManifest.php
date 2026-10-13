<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Manifest;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Orchestration\Core\Rows;
use Firefly\Orchestration\Saga\Registry\SagaDefinition;
use Firefly\Orchestration\Tcc\Registry\TccDefinition;
use Firefly\Orchestration\Workflow\Registry\WorkflowDefinition;

/**
 * The compiled, reflection-free source of every attribute-declared saga, TCC, workflow and schedule — the
 * `orchestration.php` firefly:cache writes and OrchestrationWiringProvider loads by require (the
 * ScheduledManifest / HandlerManifest idiom). It holds ROWS, pure arrays; the definitions are rebuilt from
 * them on demand, which also re-validates every topology.
 */
final class OrchestrationManifest
{
    /**
     * @param  list<array<string, mixed>>  $sagas
     * @param  list<array<string, mixed>>  $tccs
     * @param  list<array<string, mixed>>  $workflows
     * @param  list<array<string, mixed>>  $schedules
     */
    public function __construct(
        private readonly array $sagas = [],
        private readonly array $tccs = [],
        private readonly array $workflows = [],
        private readonly array $schedules = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * @param  list<SagaDefinition>  $sagas
     * @param  list<TccDefinition>  $tccs
     * @param  list<WorkflowDefinition>  $workflows
     * @param  list<ScheduledExecution>  $schedules
     */
    public static function fromDefinitions(array $sagas, array $tccs, array $workflows, array $schedules): self
    {
        return new self(
            array_map(static fn (SagaDefinition $saga): array => $saga->toArray(), $sagas),
            array_map(static fn (TccDefinition $tcc): array => $tcc->toArray(), $tccs),
            array_map(static fn (WorkflowDefinition $workflow): array => $workflow->toArray(), $workflows),
            array_map(static fn (ScheduledExecution $schedule): array => $schedule->toArray(), $schedules),
        );
    }

    /** @return list<SagaDefinition> */
    public function sagas(): array
    {
        return array_map(static fn (array $row): SagaDefinition => SagaDefinition::fromArray($row), $this->sagas);
    }

    /** @return list<TccDefinition> */
    public function tccs(): array
    {
        return array_map(static fn (array $row): TccDefinition => TccDefinition::fromArray($row), $this->tccs);
    }

    /** @return list<WorkflowDefinition> */
    public function workflows(): array
    {
        return array_map(static fn (array $row): WorkflowDefinition => WorkflowDefinition::fromArray($row), $this->workflows);
    }

    /** @return list<ScheduledExecution> */
    public function schedules(): array
    {
        return array_map(static fn (array $row): ScheduledExecution => ScheduledExecution::fromArray($row), $this->schedules);
    }

    public function isEmpty(): bool
    {
        return $this->sagas === [] && $this->tccs === [] && $this->workflows === [] && $this->schedules === [];
    }

    /** @return array{sagas: list<array<string, mixed>>, tccs: list<array<string, mixed>>, workflows: list<array<string, mixed>>, schedules: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['sagas' => $this->sagas, 'tccs' => $this->tccs, 'workflows' => $this->workflows, 'schedules' => $this->schedules];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(Rows::rows($data, 'sagas'), Rows::rows($data, 'tccs'), Rows::rows($data, 'workflows'), Rows::rows($data, 'schedules'));
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Orchestration manifest not found at {$path}. Run php artisan firefly:cache.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Orchestration manifest at {$path} did not return an array.");
        }

        return self::fromArray($data);
    }
}
