<?php

declare(strict_types=1);

use Firefly\Container\Attributes\Component;
use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Cqrs\Attributes\QueryHandler;
use Firefly\Cqrs\Tests\AttributeFixtures\ProbeCommand;
use Firefly\Cqrs\Tests\AttributeFixtures\ProbeCommandHandler;

it('makes #[CommandHandler] and #[QueryHandler] #[Component] stereotypes (IS_INSTANCEOF), so ComponentScanner registers a handler as a bean', function () {
    expect((new ReflectionClass(CommandHandler::class))->isSubclassOf(Component::class))->toBeTrue()
        ->and((new ReflectionClass(QueryHandler::class))->isSubclassOf(Component::class))->toBeTrue();

    // The shipped ComponentScanner finds stereotypes via IS_INSTANCEOF matching against Component — prove the
    // annotated handler resolves as one.
    $stereotypes = (new ReflectionClass(ProbeCommandHandler::class))
        ->getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF);

    expect($stereotypes)->toHaveCount(1);
});

it('carries the explicit message-class override on #[CommandHandler]', function () {
    $attribute = (new ReflectionClass(ProbeCommandHandler::class))
        ->getAttributes(CommandHandler::class)[0]->newInstance();

    expect($attribute->command)->toBe(ProbeCommand::class);
});

it('defaults #[QueryHandler] and #[PublishDomainEvent] arguments to null', function () {
    expect((new QueryHandler)->query)->toBeNull()
        ->and((new PublishDomainEvent)->destination)->toBeNull()
        ->and((new PublishDomainEvent('orders.events'))->destination)->toBe('orders.events');
});
