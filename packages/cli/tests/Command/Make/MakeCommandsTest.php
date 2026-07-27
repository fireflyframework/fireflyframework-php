<?php

declare(strict_types=1);

use Firefly\Cli\Tests\Command\Make\MakeCommandsTestCase;
use Firefly\Cli\Tests\Support\ArtisanAssertions;

// NOTE: the brief's literal test used `uses(new class extends FireflyTestCase { ... }::class)`. That
// instantiates the anonymous class immediately to read its ::class, which throws before Pest ever
// binds it (verified: ArgumentCountError — see MakeCommandsTestCase's docblock, and the identical fix
// in ClearCommandTestCase/IntrospectionCommandsTestCase). Following the monorepo's NAMED support-class
// convention instead.
uses(MakeCommandsTestCase::class);

// Each generator writes exactly one top-level *.php file under app_path() — except the controller,
// which lands under app_path('Http/') (MakeControllerCommand::getDefaultNamespace() appends \Http).
// Clean both locations after every test so the shared testbench workbench app/ dir stays pristine
// across the whole single-process suite run.
afterEach(function (): void {
    array_map('unlink', glob(app_path('*.php')) ?: []);
    array_map('unlink', glob(app_path('Http/*.php')) ?: []);
});

dataset('generators', [
    // command, name, relative generated path, expected needle in the generated file's contents.
    'controller' => ['make:firefly-controller', 'DemoController', 'Http/DemoController.php', '#[RestController]'],
    'service' => ['make:firefly-service', 'DemoService', 'DemoService.php', '#[Service]'],
    'component' => ['make:firefly-component', 'DemoComponent', 'DemoComponent.php', '#[Component]'],
    'handler (command)' => ['make:firefly-handler', 'DemoCommandHandler', 'DemoCommandHandler.php', '#[CommandHandler]'],
    // #[EventListener] always carries a $patterns constructor arg in the generated scaffold, so the
    // needle checks the opening paren too (an attribute usage never renders as a bare `#[EventListener]`
    // here, unlike the parameterless stereotypes above).
    'listener (event)' => ['make:firefly-listener', 'DemoEventListener', 'DemoEventListener.php', "#[EventListener('"],
    // NOTE: no `Firefly\Data\Attributes\Entity` (or any other) attribute exists anywhere in the
    // monorepo — grepping every packages/*/src/Attributes dir confirms it. The real framework analog
    // of pyfly's `@Entity`-style marker is the pure-PHP `Firefly\Domain\Entity` ABSTRACT BASE CLASS
    // (identity-based equality, no attribute, no reflection — see packages/domain/src/Entity.php), so
    // entity.stub extends it instead of applying a nonexistent attribute; the needle is adjusted to
    // match (deviation from the brief's literal `'#[Entity]'`, reported in the task report).
    'entity' => ['make:firefly-entity', 'DemoEntity', 'DemoEntity.php', 'extends Entity'],
    'repository' => ['make:firefly-repository', 'DemoRepository', 'DemoRepository.php', 'interface DemoRepository'],
    'config properties' => ['make:firefly-config-properties', 'DemoConfigProperties', 'DemoConfigProperties.php', '#[ConfigProperties('],
]);

it('generates an attribute-correct class', function (string $command, string $name, string $rel, string $needle) {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan($command, ['name' => $name]), 0);

    $path = app_path($rel);
    expect(is_file($path))->toBeTrue("expected {$rel} to be generated")
        ->and((string) file_get_contents($path))->toContain($needle);
})->with('generators');

it('generates a query handler with #[QueryHandler] under --query', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-handler', ['name' => 'DemoQueryHandler', '--query' => true]), 0);

    expect((string) file_get_contents(app_path('DemoQueryHandler.php')))->toContain('#[QueryHandler]');
});

it('generates a message listener with #[MessageListener] under --message', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-listener', ['name' => 'DemoMessageListener', '--message' => true]), 0);

    expect((string) file_get_contents(app_path('DemoMessageListener.php')))->toContain("#[MessageListener('");
});
