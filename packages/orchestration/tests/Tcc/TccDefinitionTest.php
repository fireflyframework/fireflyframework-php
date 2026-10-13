<?php

declare(strict_types=1);

use Firefly\Orchestration\Core\Argument\ParameterBinding;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\MethodInvocation;
use Firefly\Orchestration\Core\StepOptions;
use Firefly\Orchestration\Core\TccPhase;
use Firefly\Orchestration\Tcc\Builder\TccBuilder;
use Firefly\Orchestration\Tcc\Registry\TccDefinition;
use Firefly\Orchestration\Tcc\Registry\TccParticipantDefinition;
use Firefly\Orchestration\Tcc\Registry\TccPhaseDefinition;

it('orders participants by order, then declaration, and resolves effective phase options', function (): void {
    $phase = static fn (string $method, int $timeout = -1, int $retry = -1): TccPhaseDefinition => new TccPhaseDefinition(new MethodInvocation('App\\Debit', $method, [new ParameterBinding('in', ParameterBinding::IMPLICIT)]), $timeout, $retry);
    $participant = static fn (string $id, int $order, int $timeout = -1): TccParticipantDefinition => new TccParticipantDefinition($id, $order, $phase('try', 200), $phase('confirm', -1, 5), $phase('cancel'), $timeout);
    $tcc = new TccDefinition('transfer', ['credit' => $participant('credit', 2), 'debit' => $participant('debit', 1, 900), 'audit' => $participant('audit', 2)], timeoutMs: 1500, maxRetries: 2, backoffMs: 10);

    expect($tcc->participantIds())->toBe(['debit', 'credit', 'audit'])
        ->and($tcc->participant('debit')->options(TccPhase::Try, $tcc, 30.0))->toEqual(new StepOptions(2, 10, 200, false, 0.0))
        ->and($tcc->participant('debit')->options(TccPhase::Confirm, $tcc, 30.0))->toEqual(new StepOptions(5, 10, 900, false, 0.0))
        ->and($tcc->participant('credit')->options(TccPhase::Cancel, $tcc, 30.0))->toEqual(new StepOptions(2, 10, 1500, false, 0.0))
        ->and(TccDefinition::fromArray($tcc->toArray()))->toEqual($tcc);
});

it('falls back to the configured default timeout and honours retryEnabled', function (): void {
    $tcc = TccBuilder::tcc('t')->retry(false)->participant('p')->tryWith(static fn (): string => 'x')->confirmWith(static fn (): null => null)->cancelWith(static fn (): null => null)->add()->build();

    expect($tcc->participant('p')->options(TccPhase::Try, $tcc, 30.0))->toEqual(new StepOptions(0, 1000, 30000, false, 0.0));
});

it('refuses a TCC without participants and a participant without all three phases', function (): void {
    expect(fn () => new TccDefinition('empty', []))->toThrow(TopologyValidationException::class, 'TCC [empty] declares no participants.')
        ->and(fn () => TccBuilder::tcc('half')->participant('p')->tryWith(static fn (): string => 'x')->add())->toThrow(TopologyValidationException::class, 'TCC [half] participant [p] needs try, confirm and cancel');
});
