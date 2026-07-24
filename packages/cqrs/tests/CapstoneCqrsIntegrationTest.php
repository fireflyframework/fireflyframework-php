<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Cqrs\Tests\CapstoneFixtures\Banking\Account;
use Firefly\Cqrs\Tests\CapstoneFixtures\Banking\FailOpenAccount;
use Firefly\Cqrs\Tests\CapstoneFixtures\Banking\FindAccount;
use Firefly\Cqrs\Tests\CapstoneFixtures\Banking\OpenAccount;
use Firefly\Cqrs\Tests\CapstoneFixtures\Banking\OpenAccountHandler;
use Firefly\Cqrs\Tests\Support\CqrsCapstoneTestCase;
use Illuminate\Foundation\Application;

uses(CqrsCapstoneTestCase::class);

function cqrsContext(Application $app): ApplicationContext
{
    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    return $context;
}

it('(1) sends a command through the bus, commits via the #[Transactional] handler, and re-emits the integration event to the M9 EventPublisher', function () {
    /** @var CqrsCapstoneTestCase $this */
    $context = cqrsContext($this->capstoneApp());
    /** @var CommandBus $bus */
    $bus = $context->get(CommandBus::class);

    $id = $bus->send(new OpenAccount('alice', 500));

    // the write model committed
    expect($id)->toBeGreaterThan(0)
        ->and(Account::query()->where('owner', 'alice')->count())->toBe(1);

    // the committed DomainEvent was re-emitted onto the M9 broker at its #[PublishDomainEvent] destination
    expect($this->eventBus->published)->toHaveCount(1);
    $sent = $this->eventBus->published[0];
    expect($sent['destination'])->toBe('accounts.events')
        ->and($sent['eventType'])->toBe('AccountOpened')
        ->and($sent['payload']['owner'])->toBe('alice')
        ->and($sent['payload']['balance'])->toBe(500);
});

it('(2) resolves the command handler as the generated #[Transactional] proxy — interception is free', function () {
    /** @var CqrsCapstoneTestCase $this */
    $handler = cqrsContext($this->capstoneApp())->get(OpenAccountHandler::class);

    expect($handler::class)->not->toBe(OpenAccountHandler::class) // it IS the generated proxy subclass
        ->and($handler)->toBeInstanceOf(OpenAccountHandler::class);
});

it('(3) a rollback publishes NO integration event and persists no row', function () {
    /** @var CqrsCapstoneTestCase $this */
    $context = cqrsContext($this->capstoneApp());
    /** @var CommandBus $bus */
    $bus = $context->get(CommandBus::class);

    try {
        $bus->send(new FailOpenAccount('bob', 100));
    } catch (CommandProcessingException) {
        // the handler threw; the bus wrapped it — the unit of work rolled back
    }

    expect(Account::query()->where('owner', 'bob')->count())->toBe(0)
        ->and($this->eventBus->published)->toBe([]); // rollback discarded the afterCommit callbacks -> bridge never fired
});

it('(4) answers a query through the bus, running the handler on the inert cache-seam miss', function () {
    /** @var CqrsCapstoneTestCase $this */
    $context = cqrsContext($this->capstoneApp());
    /** @var CommandBus $commandBus */
    $commandBus = $context->get(CommandBus::class);
    /** @var QueryBus $queryBus */
    $queryBus = $context->get(QueryBus::class);

    $commandBus->send(new OpenAccount('carol', 10));
    $commandBus->send(new OpenAccount('carol', 20));

    expect($queryBus->ask(new FindAccount('carol')))->toBe(2); // NoOpQueryCache miss -> handler runs
});
