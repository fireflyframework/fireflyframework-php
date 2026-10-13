<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Composition;

/**
 * The composition DSL (jafly SagaCompositionBuilder):
 *
 *     SagaCompositionBuilder::composition('checkout')
 *         ->saga('stock')->sagaName('reserve-stock')->add()
 *         ->saga('charge')->sagaName('charge-card')->dependsOn('stock')->add()
 *         ->dataFlow('stock', 'reservationId', 'charge', 'reservationId')
 *         ->build();
 */
final class SagaCompositionBuilder
{
    /** @var array<string, CompositionSaga> */
    private array $sagas = [];

    /** @var list<DataMapping> */
    private array $dataMappings = [];

    private function __construct(private readonly string $name) {}

    public static function composition(string $name): self
    {
        return new self($name);
    }

    public function saga(string $alias): CompositionSagaBuilder
    {
        return new CompositionSagaBuilder($this, $alias);
    }

    public function dataFlow(string $sourceSaga, string $sourceField, string $targetSaga, string $targetField): self
    {
        $this->dataMappings[] = new DataMapping($sourceSaga, $sourceField, $targetSaga, $targetField);

        return $this;
    }

    /** @internal called by CompositionSagaBuilder::add() */
    public function addSaga(CompositionSaga $saga): self
    {
        $this->sagas[$saga->alias] = $saga;

        return $this;
    }

    public function build(): SagaComposition
    {
        return new SagaComposition($this->name, $this->sagas, $this->dataMappings);
    }
}
