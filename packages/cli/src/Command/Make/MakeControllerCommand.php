<?php

declare(strict_types=1);

namespace Firefly\Cli\Command\Make;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * `make:firefly-controller` — scaffolds a FULL REST resource into app/Http: a #[RestController] with the five
 * actions `artisan make:controller --resource` gives a Laravel developer (index/show/store/update/destroy),
 * plus the request DTO its `store`/`update` bodies bind. `--plain` falls back to the single-action shape.
 *
 * WHAT WAS WRONG. The generator emitted ONE action, `index()`, mapped to `#[GetMapping('/{{ class }}')]` —
 * the PHP class name substituted straight into a URL. `make:firefly-controller OrderController` therefore
 * produced a route at `/OrderController`: capitalised, singular, and carrying the word "Controller" in the
 * path. That is not a URL anyone ships, so the first thing every developer did with the framework's own
 * scaffold was delete what it had just written. Worse, it set the expectation that a Firefly controller IS a
 * single action, when the framework has verb attributes for the whole REST surface and an argument resolver
 * built to bind validated request bodies into it. The scaffold was advertising a fraction of the framework.
 *
 * WHAT REPLACES IT, AND WHY THE DTO IS PART OF IT. A REST resource whose base path is derived from the
 * resource name (OrderController -> /orders, OrderItemController -> /order-items, PersonController ->
 * /people), declared once as a class-level #[RequestMapping] so each action carries only its own suffix. The
 * `store`/`update` actions take a `#[Valid] #[RequestBody]` DTO, and that DTO is GENERATED ALONGSIDE the
 * controller — exactly as `make:firefly-handler` generates the command class its handler's `handle()` takes,
 * and for a stronger reason than symmetry: the controller names the DTO type in its signature, and
 * `firefly:cache` reflects every controller parameter to compile its binding plan. A generated controller
 * whose request type did not exist would not merely be incomplete, it would be a file PHP cannot load — so
 * emitting the controller without the DTO would reproduce, in the web layer, precisely the "scaffold that
 * poisons the next `firefly:cache`" defect that MakeHandlerCommand was rewritten to fix.
 *
 * WHY `--plain` STILL EXISTS. Not every #[RestController] is a collection of things. Webhook receivers,
 * probes, report endpoints and RPC-shaped action verbs are single-action controllers with no member id and
 * no request DTO, and they are common enough that making the CRUD resource the ONLY output would mean
 * deleting four methods and a whole second file every time. `--plain` is the escape hatch, and it is a flag
 * rather than the default because the resource is the shape the framework most needs to demonstrate and the
 * one a developer coming from `make:controller --resource` expects. It is named `--plain` rather than
 * `--api` deliberately: Laravel's `--api` means "a resource controller MINUS create/edit", which is a
 * distinction that only exists in a framework with HTML form routes — Firefly's resource has no create/edit
 * actions to remove, so borrowing the name would promise a difference that is not there.
 */
final class MakeControllerCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:firefly-controller';

    /** @var string */
    protected $description = 'Create a Firefly #[RestController] REST resource and its request DTO (or a single action with --plain).';

    /** @var string */
    protected $type = 'Firefly controller';

    /**
     * Set for the duration of the request-DTO emission so buildClass() picks the DTO stub instead of the
     * controller stub. GeneratorCommand offers no per-call stub argument — buildClass() always asks
     * getStub() — so this one-field override is the seam for writing a second file through the parent's own
     * namespace/class replacement machinery rather than re-implementing it. (Same technique, same reason, as
     * MakeHandlerCommand::$messageStub.)
     */
    private ?string $requestStub = null;

    protected function getStub(): string
    {
        if ($this->requestStub !== null) {
            return $this->requestStub;
        }

        return __DIR__.'/../../../stubs/'.($this->option('plain') ? 'controller.stub' : 'controller-resource.stub');
    }

    /**
     * MUST keep $rootNamespace UNTYPED: the parent GeneratorCommand::getDefaultNamespace($rootNamespace)
     * declares no parameter type, and PHP's parameter-contravariance rule requires an override's
     * parameter type to be at least as broad as the parent's — "no type" is the broadest possible, so a
     * native `string $rootNamespace` here would be a PHP FATAL at class-load time, not a lint nit.
     *
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Http';
    }

    /**
     * Writes the controller (parent) and then, unless `--plain`, its request DTO. A false return from the
     * parent means it refused — reserved name, or the controller already exists — and in that case nothing
     * else is written, so a re-run never drops a stray DTO next to code it did not generate.
     */
    public function handle(): ?bool
    {
        if (parent::handle() === false) {
            return false;
        }

        if (! $this->option('plain')) {
            $this->writeRequestClass();
        }

        return null;
    }

    /**
     * Emits the request DTO next to the controller, in the same namespace. An existing file is left ALONE
     * and reported: the developer has almost certainly already filled it with the real properties and
     * constraints, and the controller that was just generated references it by name either way, so reusing
     * it is the correct outcome.
     */
    private function writeRequestClass(): void
    {
        $name = $this->qualifiedRequestClass();
        $path = $this->getPath($name);

        if ($this->files->exists($path)) {
            $this->components->info(sprintf('Firefly request DTO [%s] already exists; reusing it.', $path));

            return;
        }

        $this->requestStub = __DIR__.'/../../../stubs/controller-request.stub';

        try {
            $this->makeDirectory($path);
            $this->files->put($path, $this->sortImports($this->buildClass($name)));
        } finally {
            $this->requestStub = null;
        }

        $this->components->info(sprintf('Firefly request DTO [%s] created successfully.', $path));
    }

    /**
     * Substitutes the three placeholders the parent knows nothing about, on top of its `{{ class }}`:
     * `{{ resourcePath }}` (the derived collection path), `{{ request }}` (the DTO's SHORT name — controller
     * and DTO share a namespace and need no import) and `{{ controller }}` (the controller's short name, so
     * the DTO's docblock can name the class that binds it).
     *
     * MUST keep both parameters UNTYPED. The parent declares `replaceClass($stub, $name)` with no parameter
     * types, and PHP's contravariance rule requires an override's parameters to be at least as broad — a
     * native `string` here would be a FATAL at class-load time (the same trap getDefaultNamespace()
     * documents above).
     *
     * @param  string  $stub
     * @param  string  $name
     */
    protected function replaceClass($stub, $name): string
    {
        $stub = parent::replaceClass($stub, $name);

        return str_replace(
            ['{{ resourcePath }}', '{{resourcePath}}', '{{ request }}', '{{request}}', '{{ controller }}', '{{controller}}'],
            [$this->resourcePath(), $this->resourcePath(), $this->shortRequestClass(), $this->shortRequestClass(), $this->shortControllerClass(), $this->shortControllerClass()],
            $stub,
        );
    }

    /**
     * The collection path: the resource name kebab-cased and then pluralised — OrderController -> `orders`,
     * OrderItemController -> `order-items`, PersonController -> `people`, CategoryController -> `categories`.
     *
     * Kebab BEFORE plural on purpose. Str::plural inflects the last word of what it is given, so handing it
     * the already-hyphenated `order-item` lets it see `item` as the trailing word; the two orders happen to
     * agree on every name tried, but this one keeps the inflector's input in the lowercase, single-word shape
     * its irregular-noun tables are written for.
     *
     * Only the SHORT class name is used, so `make:firefly-controller Admin/OrderController` still serves
     * `/orders` — the PHP sub-namespace is a code-organisation choice and has never implied a URL prefix in
     * this framework (a URL prefix is what the class-level #[RequestMapping] the scaffold writes is for, and
     * it is one edit away).
     */
    private function resourcePath(): string
    {
        return Str::plural(Str::kebab($this->resourceName()));
    }

    /**
     * The resource name behind the controller: a trailing "Controller" stripped (OrderController -> Order),
     * or the name as typed when there is nothing to strip (Orders -> Orders). The length guard keeps a class
     * literally named `Controller` from collapsing to the empty string and producing the path `/`.
     */
    private function resourceName(): string
    {
        $short = $this->shortControllerClass();

        return str_ends_with($short, 'Controller') && strlen($short) > strlen('Controller')
            ? substr($short, 0, -strlen('Controller'))
            : $short;
    }

    private function shortControllerClass(): string
    {
        $segments = explode('\\', str_replace('/', '\\', ltrim($this->getNameInput(), '\\/')));

        return (string) end($segments);
    }

    /**
     * The DTO's short name: the resource plus "Request" (OrderController -> OrderRequest). It can never
     * collide with the controller's own name — the two differ by the stripped "Controller" suffix, and a
     * name with no suffix to strip still differs by the appended "Request" — which matters because a
     * collision would make the two files fight over one path.
     */
    private function shortRequestClass(): string
    {
        return $this->resourceName().'Request';
    }

    /**
     * The DTO's fully-qualified name, derived from the controller name the developer typed so that a nested
     * `make:firefly-controller Admin/OrderController` puts both files in the same sub-namespace.
     */
    private function qualifiedRequestClass(): string
    {
        $segments = explode('\\', str_replace('/', '\\', ltrim($this->getNameInput(), '\\/')));
        array_pop($segments);
        $segments[] = $this->shortRequestClass();

        return $this->qualifyClass(implode('\\', $segments));
    }

    /** @return list<array{0: string, 1: string|null, 2: int, 3: string}> */
    protected function getOptions(): array
    {
        return [
            ['plain', null, InputOption::VALUE_NONE, 'Generate a single-action controller with no request DTO.'],
        ];
    }
}
