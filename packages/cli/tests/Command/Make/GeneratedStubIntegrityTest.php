<?php

declare(strict_types=1);

use Firefly\Cli\Cache\FireflyCachePaths;
use Firefly\Cli\Tests\Command\Make\MakeCommandsTestCase;
use Firefly\Cli\Tests\Support\ArtisanAssertions;
use Firefly\Cli\Tests\Support\GeneratedApp;
use Firefly\Cli\Tests\Support\GeneratedAppAutoloader;
use Firefly\Config\Scanner\ConfigPropertiesScanner;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Cqrs\Handler\HandlerDescriptor;
use Firefly\Cqrs\Handler\HandlerKind;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Scanner\HandlerScanner;
use Firefly\Data\Repository\CrudRepository;
use Firefly\Domain\Entity;
use Firefly\Eda\Scanner\EventListenerScanner;
use Firefly\Messaging\Scanner\MessageListenerScanner;
use Firefly\Web\Route\RouteScanner;

/**
 * The behavioural counterpart to MakeCommandsTest.
 *
 * MakeCommandsTest asserts that each generated file CONTAINS the right attribute needle. That is a template
 * check, and a template check cannot see a scaffold that is syntactically perfect and semantically dead —
 * which is precisely how three broken stubs shipped:
 *
 *  - command-handler.stub / query-handler.stub emitted `handle(object $command)` under a bare
 *    #[CommandHandler]. `object` is a BUILTIN type, so HandlerScanner — which infers the handled message
 *    from handle()'s sole parameter — threw CqrsConfigurationException. ManifestCacheWriter calls that
 *    scanner unconditionally and catches nothing, so the first `firefly:cache` after a
 *    `make:firefly-handler` aborted the ENTIRE compile: no handler manifest, and none of the
 *    event/message/scheduled/security/transactional manifests or proxies that are written after it.
 *    `'#[CommandHandler]'` was present in the file the whole time.
 *  - repository.stub emitted an `interface` extending CrudRepository. Nothing synthesises an
 *    implementation for it, so injecting the type failed to resolve — and ComponentScanner::describe()
 *    returns null for an interface, so it was not even in the component manifest.
 *  - event-listener.stub / message-listener.stub emitted a class with no #[Component]-derived stereotype,
 *    so the listener was never registered as a bean even though both attributes are documented as marking
 *    "a public BEAN method".
 *
 * Every test here therefore GENERATES from the real stub through the real Artisan command, lints the
 * output with `php -l`, and then hands it to the same scanner `firefly:cache` runs. Anything that would
 * make `firefly:cache` throw, or make the framework fail to see the class, fails here first.
 */
uses(MakeCommandsTestCase::class);

// The scanners discover classes through class_exists(); the monorepo autoloader maps `App\` at Pint's
// source tree, so without this fallback every scan below would come back empty and pass vacuously.
GeneratedAppAutoloader::register();

beforeEach(function (): void {
    GeneratedApp::clean();
});

afterEach(function (): void {
    GeneratedApp::clean();
});

it('generates a #[CommandHandler] whose message type firefly:cache can actually resolve', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-handler', ['name' => 'StubCommandHandler']), 0);

    // FIRST, before any file assertion: the exact call ManifestCacheWriter::writeManifests() makes. On the
    // old stub this line threw CqrsConfigurationException instead of returning, taking the whole
    // `firefly:cache` run with it — so it is deliberately the very first thing this test does.
    $scan = (new HandlerScanner)->scan(GeneratedApp::psr4());

    GeneratedApp::lint(GeneratedApp::path().'/StubCommandHandler.php');
    GeneratedApp::lint(GeneratedApp::path().'/StubCommand.php');

    expect($scan['handlers'])->toHaveCount(1);
    expect($scan['handlers'][0]->handlerClass)->toBe('App\\StubCommandHandler')
        ->and($scan['handlers'][0]->messageClass)->toBe('App\\StubCommand')
        ->and($scan['handlers'][0]->method)->toBe('handle')
        ->and($scan['handlers'][0]->kind)->toBe(HandlerKind::Command);
});

it('generates a #[QueryHandler] whose message type firefly:cache can actually resolve', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-handler', ['name' => 'StubQueryHandler', '--query' => true]), 0);

    $scan = (new HandlerScanner)->scan(GeneratedApp::psr4());

    GeneratedApp::lint(GeneratedApp::path().'/StubQueryHandler.php');
    GeneratedApp::lint(GeneratedApp::path().'/StubQuery.php');

    expect($scan['handlers'])->toHaveCount(1);
    expect($scan['handlers'][0]->handlerClass)->toBe('App\\StubQueryHandler')
        ->and($scan['handlers'][0]->messageClass)->toBe('App\\StubQuery')
        ->and($scan['handlers'][0]->kind)->toBe(HandlerKind::Query);
});

it('appends Command/Query when the handler name carries no Handler suffix', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-handler', ['name' => 'StubRegisterWidget']), 0);

    // The message must not collide with the handler over one file, so a name with nothing to strip gets
    // the CQRS side appended instead.
    GeneratedApp::lint(GeneratedApp::path().'/StubRegisterWidgetCommand.php');

    $scan = (new HandlerScanner)->scan(GeneratedApp::psr4());

    expect($scan['handlers'])->toHaveCount(1);
    expect($scan['handlers'][0]->messageClass)->toBe('App\\StubRegisterWidgetCommand');
});

it('keeps the handler and its message in the same sub-namespace', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-handler', ['name' => 'Widget/StubNestedHandler']), 0);

    GeneratedApp::lint(GeneratedApp::path().'/Widget/StubNestedHandler.php');
    GeneratedApp::lint(GeneratedApp::path().'/Widget/StubNested.php');

    $scan = (new HandlerScanner)->scan(GeneratedApp::psr4());

    expect($scan['handlers'])->toHaveCount(1);
    expect($scan['handlers'][0]->handlerClass)->toBe('App\\Widget\\StubNestedHandler')
        ->and($scan['handlers'][0]->messageClass)->toBe('App\\Widget\\StubNested');
});

it('never overwrites a message class the developer already wrote', function (): void {
    /** @var MakeCommandsTestCase $this */
    $existing = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App;

        final readonly class StubKeptCommand
        {
            public function __construct(public string $name) {}
        }

        PHP;
    file_put_contents(GeneratedApp::path().'/StubKeptCommand.php', $existing);

    ArtisanAssertions::exitCode($this->artisan('make:firefly-handler', ['name' => 'StubKeptCommandHandler']), 0);

    expect((string) file_get_contents(GeneratedApp::path().'/StubKeptCommand.php'))->toBe($existing);

    $scan = (new HandlerScanner)->scan(GeneratedApp::psr4());
    expect($scan['handlers'])->toHaveCount(1);
    expect($scan['handlers'][0]->messageClass)->toBe('App\\StubKeptCommand');
});

it('generates a repository that is a resolvable bean, not an unbindable interface', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-repository', ['name' => 'StubRepository']), 0);

    GeneratedApp::lint(GeneratedApp::path().'/StubRepository.php');

    $descriptors = (new ComponentScanner)->scan(GeneratedApp::psr4());

    expect($descriptors)->toHaveCount(1);
    expect($descriptors[0]->class)->toBe('App\\StubRepository')
        ->and($descriptors[0]->stereotype)->toBe('repository')
        // An interface is not instantiable and ComponentScanner skips it outright — the two facts that
        // made the old scaffold impossible to inject.
        ->and(GeneratedApp::reflect('App\\StubRepository')->isInstantiable())->toBeTrue()
        ->and($descriptors[0]->interfaces)->toContain(CrudRepository::class);
});

it('generates listeners that are discoverable beans as well as discoverable listeners', function (string $name, array $options, string $scanned): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-listener', ['name' => $name, ...$options]), 0);

    GeneratedApp::lint(GeneratedApp::path().'/'.$name.'.php');

    // Half one: the transport scanner sees the annotated method.
    $descriptors = $scanned === 'event'
        ? (new EventListenerScanner)->scan(GeneratedApp::psr4())
        : (new MessageListenerScanner)->scan(GeneratedApp::psr4());

    expect($descriptors)->toHaveCount(1);
    expect($descriptors[0]->class)->toBe('App\\'.$name);

    // Half two — the half that was missing: the class is a BEAN. Both wiring passes resolve the target
    // through the container per delivery, and only a component-manifest entry makes that a managed,
    // post-processed Firefly bean rather than an ad-hoc reflective build.
    $components = (new ComponentScanner)->scan(GeneratedApp::psr4());

    expect($components)->toHaveCount(1);
    expect($components[0]->class)->toBe('App\\'.$name)
        ->and($components[0]->stereotype)->toBe('component');
})->with([
    'event listener' => ['StubEventListener', [], 'event'],
    'message listener' => ['StubMessageListener', ['--message' => true], 'message'],
]);

it('generates a controller the component scan and the route scan both see', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-controller', ['name' => 'StubController']), 0);

    GeneratedApp::lint(GeneratedApp::path().'/Http/StubController.php');

    $components = (new ComponentScanner)->scan(GeneratedApp::psr4());
    expect($components)->toHaveCount(1);
    expect($components[0]->class)->toBe('App\\Http\\StubController')
        ->and($components[0]->stereotype)->toBe('restcontroller');

    $routes = (new RouteScanner)->scan(GeneratedApp::psr4());
    expect($routes)->toHaveCount(1);
    expect($routes[0]->controllerClass)->toBe('App\\Http\\StubController')
        ->and($routes[0]->methodName)->toBe('index')
        ->and($routes[0]->httpMethod)->toBe('GET');
});

it('generates plain stereotypes the component scan registers', function (string $command, string $name, string $stereotype): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan($command, ['name' => $name]), 0);

    GeneratedApp::lint(GeneratedApp::path().'/'.$name.'.php');

    $descriptors = (new ComponentScanner)->scan(GeneratedApp::psr4());

    expect($descriptors)->toHaveCount(1);
    expect($descriptors[0]->class)->toBe('App\\'.$name)
        ->and($descriptors[0]->stereotype)->toBe($stereotype);
})->with([
    'service' => ['make:firefly-service', 'StubService', 'service'],
    'component' => ['make:firefly-component', 'StubComponent', 'component'],
]);

it('generates a #[ConfigProperties] DTO the config scan binds', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-config-properties', ['name' => 'StubProperties']), 0);

    GeneratedApp::lint(GeneratedApp::path().'/StubProperties.php');

    $descriptors = (new ConfigPropertiesScanner)->scan(GeneratedApp::psr4());

    expect($descriptors)->toHaveCount(1);
    expect($descriptors[0]->class)->toBe('App\\StubProperties')
        ->and($descriptors[0]->prefix)->toBe('StubProperties');
});

it('generates an entity that is a concrete, instantiable Firefly\Domain\Entity', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-entity', ['name' => 'StubEntity']), 0);

    GeneratedApp::lint(GeneratedApp::path().'/StubEntity.php');

    // class_exists() is the load-bearing assertion: Entity is abstract, so a stub that failed to satisfy
    // its contract would fatal here rather than merely lint badly.
    expect(class_exists('App\\StubEntity'))->toBeTrue()
        ->and(get_parent_class('App\\StubEntity'))->toBe(Entity::class)
        ->and(GeneratedApp::reflect('App\\StubEntity')->isInstantiable())->toBeTrue();

    // An entity carries no stereotype by design, so it must NOT turn up as a bean.
    expect((new ComponentScanner)->scan(GeneratedApp::psr4()))->toBe([]);
});

it('compiles a whole scaffolded app in one real firefly:cache run', function (): void {
    /** @var MakeCommandsTestCase $this */
    $generated = [
        ['make:firefly-controller', ['name' => 'CompiledController']],
        ['make:firefly-service', ['name' => 'CompiledService']],
        ['make:firefly-component', ['name' => 'CompiledComponent']],
        ['make:firefly-repository', ['name' => 'CompiledRepository']],
        ['make:firefly-entity', ['name' => 'CompiledEntity']],
        ['make:firefly-config-properties', ['name' => 'CompiledProperties']],
        ['make:firefly-handler', ['name' => 'CompiledRegisterHandler']],
        ['make:firefly-handler', ['name' => 'CompiledCountHandler', '--query' => true]],
        ['make:firefly-listener', ['name' => 'CompiledEventListener']],
        ['make:firefly-listener', ['name' => 'CompiledMessageListener', '--message' => true]],
    ];

    foreach ($generated as [$command, $arguments]) {
        ArtisanAssertions::exitCode($this->artisan($command, $arguments), 0);
    }

    $dir = sys_get_temp_dir().'/firefly-stub-cache-'.bin2hex(random_bytes(6));
    config()->set('firefly.scan.paths', GeneratedApp::psr4());
    config()->set('firefly.cache.path', $dir);

    try {
        // The end-to-end statement of the whole fix: scaffold one of everything, then run the exact command
        // the README and the book tell a developer to run next. On the old handler stub this aborted with an
        // uncaught CqrsConfigurationException part-way through ManifestCacheWriter::writeManifests(), so the
        // handler/event/message/scheduled/security/transactional artifacts were never written at all.
        ArtisanAssertions::exitCode($this->artisan('firefly:cache'), 0);

        foreach ([FireflyCachePaths::COMPONENT, FireflyCachePaths::ROUTES, FireflyCachePaths::HANDLERS, FireflyCachePaths::EVENT_LISTENERS, FireflyCachePaths::MESSAGE_LISTENERS, FireflyCachePaths::TRANSACTIONAL, FireflyCachePaths::PROXY_MAP] as $basename) {
            expect(is_file($dir.'/'.$basename))->toBeTrue("expected firefly:cache to write {$basename}");
        }

        // Read the artifacts back through the framework's own loaders — the same call the cached boot makes.
        $handlers = HandlerManifest::load($dir.'/'.FireflyCachePaths::HANDLERS);
        expect(array_map(static fn (HandlerDescriptor $d): string => $d->handlerClass, $handlers->handlers()))
            ->toContain('App\\CompiledRegisterHandler')
            ->toContain('App\\CompiledCountHandler');

        $components = ComponentManifest::load($dir.'/'.FireflyCachePaths::COMPONENT);
        expect(array_map(static fn (ComponentDescriptor $d): string => $d->class, $components->components))
            ->toContain('App\\CompiledRepository')
            ->toContain('App\\CompiledEventListener')
            ->toContain('App\\CompiledMessageListener');
    } finally {
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($dir.'/'.FireflyCachePaths::PROXY_DIR.'/*.php') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($dir.'/'.FireflyCachePaths::PROXY_DIR);
        @rmdir($dir);
    }
});
