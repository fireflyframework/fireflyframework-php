<?php

declare(strict_types=1);

use Firefly\Cqrs\Exception\CqrsConfigurationException;
use Firefly\Cqrs\Handler\HandlerDescriptor;
use Firefly\Cqrs\Handler\HandlerKind;
use Firefly\Cqrs\Scanner\HandlerScanner;
use Firefly\Cqrs\Tests\ScannerFixtures\CreateWidget;
use Firefly\Cqrs\Tests\ScannerFixtures\CreateWidgetHandler;
use Firefly\Cqrs\Tests\ScannerFixtures\FindWidget;
use Firefly\Cqrs\Tests\ScannerFixtures\FindWidgetHandler;
use Firefly\Cqrs\Tests\ScannerFixtures\RenameWidget;
use Firefly\Cqrs\Tests\ScannerFixtures\RenameWidgetHandler;
use Firefly\Cqrs\Tests\ScannerFixtures\WidgetCreated;

/**
 * @return array{handlers: list<HandlerDescriptor>, destinations: array<string,string>}
 */
function scanHandlerFixtures(string $subdir, string $namespace): array
{
    return (new HandlerScanner)->scan([$namespace => dirname(__DIR__).'/'.$subdir]);
}

it('infers the message type from the handle() parameter and reads the explicit override', function () {
    $result = scanHandlerFixtures('ScannerFixtures', 'Firefly\\Cqrs\\Tests\\ScannerFixtures\\');

    $byHandler = [];
    foreach ($result['handlers'] as $descriptor) {
        $byHandler[$descriptor->handlerClass] = $descriptor;
    }

    expect($byHandler[CreateWidgetHandler::class]->messageClass)->toBe(CreateWidget::class)
        ->and($byHandler[CreateWidgetHandler::class]->kind)->toBe(HandlerKind::Command)
        ->and($byHandler[CreateWidgetHandler::class]->method)->toBe('handle')
        ->and($byHandler[FindWidgetHandler::class]->messageClass)->toBe(FindWidget::class)
        ->and($byHandler[FindWidgetHandler::class]->kind)->toBe(HandlerKind::Query)
        ->and($byHandler[RenameWidgetHandler::class]->messageClass)->toBe(RenameWidget::class) // explicit override, param is object
        ->and($byHandler[RenameWidgetHandler::class]->kind)->toBe(HandlerKind::Command);
});

it('collects #[PublishDomainEvent] destinations off DomainEvent subclasses only', function () {
    $result = scanHandlerFixtures('ScannerFixtures', 'Firefly\\Cqrs\\Tests\\ScannerFixtures\\');

    expect($result['destinations'])->toBe([WidgetCreated::class => 'widgets.events']); // WidgetArchived (no attr) AND NotAnEvent (not a DomainEvent) both absent
});

it('fails loud when the message type cannot be inferred and no explicit override is given', function () {
    scanHandlerFixtures('ScannerBadFixtures', 'Firefly\\Cqrs\\Tests\\ScannerBadFixtures\\');
})->throws(CqrsConfigurationException::class);

// Isolates the ReflectionNamedType/isBuiltin() guard: ObjectParamHandler is the SOLE class in its directory, so the
// only route to a throw is the builtin-param branch (no no-handle sibling to short the scan first). This pins that
// branch under mutation testing — removing the isBuiltin() guard makes the scan return 'object' as the message class.
it('fails loud when the sole handle() parameter is the builtin object type with no explicit override', function () {
    scanHandlerFixtures('ScannerObjectFixtures', 'Firefly\\Cqrs\\Tests\\ScannerObjectFixtures\\');
})->throws(CqrsConfigurationException::class);
