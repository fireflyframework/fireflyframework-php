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
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\Constraint\ConstraintScanner;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteManifest;
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

/**
 * The `store` route of whichever resource controller currently sits in the generated app.
 *
 * A named helper rather than an inline loop in three tests: the scan returns a list, so every caller would
 * otherwise carry the same `RouteDescriptor|null` that PHPStan (level max) rightly refuses to dereference.
 * Throwing here also makes "the generator emitted no store action at all" fail with a sentence rather than
 * with a null-property access several assertions later.
 *
 * A class-based helper (the ArtisanAssertions/GeneratedApp convention) is unnecessary for a function this
 * local, but the name still has to be globally unique: the whole monorepo suite runs in ONE PHPUnit process,
 * so a second file declaring `storeAction()` would fatal with "Cannot redeclare function".
 */
function storeAction(): RouteDescriptor
{
    foreach ((new RouteScanner)->scan(GeneratedApp::psr4()) as $route) {
        if ($route->methodName === 'store') {
            return $route;
        }
    }

    throw new RuntimeException('no generated controller exposes a store action.');
}

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

it('generates a controller whose five REST actions the route scan compiles onto a derived path', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-controller', ['name' => 'StubOrderController']), 0);

    // BOTH files, and both syntactically valid: the controller names the DTO in its signature, so a
    // controller emitted alone would be a file PHP cannot even load once the scanners reflect it.
    GeneratedApp::lint(GeneratedApp::path().'/Http/StubOrderController.php');
    GeneratedApp::lint(GeneratedApp::path().'/Http/StubOrderRequest.php');

    // Exactly ONE bean: the controller. A request DTO carries no stereotype by design — it is hydrated per
    // request from the body, never injected, and registering it as a singleton would be actively wrong.
    $components = (new ComponentScanner)->scan(GeneratedApp::psr4());
    expect($components)->toHaveCount(1);
    expect($components[0]->class)->toBe('App\\Http\\StubOrderController')
        ->and($components[0]->stereotype)->toBe('restcontroller');

    $routes = (new RouteScanner)->scan(GeneratedApp::psr4());
    $actual = [];
    foreach ($routes as $route) {
        $actual[$route->methodName] = [$route->httpMethod, $route->path, $route->status];
    }

    // The whole point of the rewrite: five actions on a plural, kebab-cased path derived from the resource
    // name — NOT one action mapped to `/StubOrderController`, which is what this scaffold used to emit.
    expect($actual)->toBe([
        'index' => ['GET', '/stub-orders', 200],
        'show' => ['GET', '/stub-orders/{id}', 200],
        'store' => ['POST', '/stub-orders', 201],
        'update' => ['PUT', '/stub-orders/{id}', 200],
        'destroy' => ['DELETE', '/stub-orders/{id}', 204],
    ]);
});

it('compiles a request-body binding plan the argument resolver can actually hydrate', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-controller', ['name' => 'StubOrderController']), 0);

    $body = null;
    foreach (storeAction()->bindings as $binding) {
        if ($binding['kind'] === 'body') {
            $body = $binding;
        }
    }

    // A `body` binding at all is the assertion that matters. RouteScanner classifies an un-attributed class
    // parameter as a container SERVICE; only #[RequestBody] makes it a body, and only #[Valid] makes the
    // resolver run the compiled constraints before hydrating. `properties` is the constructor plan the
    // reflection-free resolver unpacks by name — an empty list there means the DTO type did not resolve.
    if ($body === null) {
        throw new RuntimeException('the generated store action has no #[RequestBody] binding.');
    }

    expect($body['type'])->toBe('App\\Http\\StubOrderRequest')
        ->and($body['valid'])->toBeTrue()
        ->and($body['required'])->toBeTrue()
        ->and($body['properties'])->toBe(['name', 'description']);
});

it('generates a request DTO whose constraints the validation compiler turns into real rules', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-controller', ['name' => 'StubOrderController']), 0);

    // The same call ManifestCacheWriter::writeManifests() makes for constraints.php. A DTO whose attributes
    // compiled to nothing would still lint, still hydrate, and silently accept any body at all.
    $rules = (new ConstraintScanner)->scan('App\\Http\\StubOrderRequest');

    expect(array_keys($rules))->toBe(['name', 'description']);
    expect($rules['name'])->toContain('required');

    // `?string $description` admits null, so Jakarta's null contract applies: `nullable` is prepended and an
    // explicit `{"description": null}` behaves exactly like an omitted key.
    expect($rules['description'][0])->toBe('nullable');
});

it('derives the collection path by kebab-casing and pluralising the resource name', function (string $class, string $path): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-controller', ['name' => $class]), 0);

    $paths = [];
    foreach ((new RouteScanner)->scan(GeneratedApp::psr4()) as $route) {
        $paths[$route->methodName] = $route->path;
    }

    expect($paths['index'])->toBe($path);
})->with([
    'simple noun' => ['StubOrderController', '/stub-orders'],
    'compound noun' => ['StubOrderItemController', '/stub-order-items'],
    'irregular plural' => ['StubPersonController', '/stub-people'],
    'consonant + y' => ['StubCategoryController', '/stub-categories'],
]);

it('keeps the controller and its request DTO in the same sub-namespace', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-controller', ['name' => 'Widget/StubNestedController']), 0);

    GeneratedApp::lint(GeneratedApp::path().'/Http/Widget/StubNestedController.php');
    GeneratedApp::lint(GeneratedApp::path().'/Http/Widget/StubNestedRequest.php');

    $routes = (new RouteScanner)->scan(GeneratedApp::psr4());
    expect($routes)->toHaveCount(5);
    expect($routes[0]->controllerClass)->toBe('App\\Http\\Widget\\StubNestedController');

    // A PHP sub-namespace is a code-organisation choice and has never implied a URL prefix here, so the
    // derived path is still the bare collection — the class-level #[RequestMapping] is the one place to
    // change that.
    foreach ($routes as $route) {
        expect($route->path)->toStartWith('/stub-nesteds');
    }
});

it('never overwrites a request DTO the developer already wrote', function (): void {
    /** @var MakeCommandsTestCase $this */
    $existing = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Http;

        final readonly class StubKeptRequest
        {
            public function __construct(public string $name = '', public ?string $description = null) {}
        }

        PHP;
    if (! is_dir(GeneratedApp::path().'/Http')) {
        mkdir(GeneratedApp::path().'/Http', 0o755, true);
    }
    file_put_contents(GeneratedApp::path().'/Http/StubKeptRequest.php', $existing);

    ArtisanAssertions::exitCode($this->artisan('make:firefly-controller', ['name' => 'StubKeptController']), 0);

    expect((string) file_get_contents(GeneratedApp::path().'/Http/StubKeptRequest.php'))->toBe($existing);

    // And the controller that was just generated still binds it — reusing the developer's own DTO is the
    // correct outcome, not a second file with a mangled name.
    expect(storeAction()->bindings[0]['type'])->toBe('App\\Http\\StubKeptRequest');
});

it('generates a single-action controller with no DTO under --plain', function (): void {
    /** @var MakeCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('make:firefly-controller', ['name' => 'StubPlainController', '--plain' => true]), 0);

    GeneratedApp::lint(GeneratedApp::path().'/Http/StubPlainController.php');

    // The escape hatch for the endpoints that are not a collection: no request DTO is written at all.
    expect(is_file(GeneratedApp::path().'/Http/StubPlainRequest.php'))->toBeFalse();

    $routes = (new RouteScanner)->scan(GeneratedApp::psr4());
    expect($routes)->toHaveCount(1);
    expect($routes[0]->controllerClass)->toBe('App\\Http\\StubPlainController')
        ->and($routes[0]->methodName)->toBe('index')
        ->and($routes[0]->httpMethod)->toBe('GET')
        // Even the single-action shape gets a real path: it used to be `/StubPlainController`.
        ->and($routes[0]->path)->toBe('/stub-plains');
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

        foreach ([FireflyCachePaths::COMPONENT, FireflyCachePaths::ROUTES, FireflyCachePaths::CONSTRAINTS, FireflyCachePaths::HANDLERS, FireflyCachePaths::EVENT_LISTENERS, FireflyCachePaths::MESSAGE_LISTENERS, FireflyCachePaths::TRANSACTIONAL, FireflyCachePaths::PROXY_MAP] as $basename) {
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

        // The generated REST resource compiled whole: all five actions in the route manifest, and the
        // request DTO that the two body-taking actions reference in the CONSTRAINT manifest. The second half
        // is the one that would silently rot — a DTO whose attributes failed to compile still lints, still
        // hydrates, and quietly accepts anything a client sends.
        $routes = RouteManifest::load($dir.'/'.FireflyCachePaths::ROUTES)->all();
        $resource = [];
        foreach ($routes as $route) {
            if ($route->controllerClass === 'App\\Http\\CompiledController') {
                $resource[$route->methodName] = $route->httpMethod.' '.$route->path;
            }
        }
        expect($resource)->toBe([
            'index' => 'GET /compileds',
            'show' => 'GET /compileds/{id}',
            'store' => 'POST /compileds',
            'update' => 'PUT /compileds/{id}',
            'destroy' => 'DELETE /compileds/{id}',
        ]);

        expect(ConstraintManifest::load($dir.'/'.FireflyCachePaths::CONSTRAINTS)->rulesFor('App\\Http\\CompiledRequest'))
            ->toHaveKey('name')
            ->toHaveKey('description');
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
