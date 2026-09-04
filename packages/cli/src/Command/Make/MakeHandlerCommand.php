<?php

declare(strict_types=1);

namespace Firefly\Cli\Command\Make;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * `make:firefly-handler` — scaffolds a #[CommandHandler] by default, or a #[QueryHandler] with
 * `--query` (pyfly's `generate handler` parity, split by CQRS side via one flag).
 *
 * It emits TWO files: the handler AND the message class the handler's `handle()` takes. That pairing is not
 * a convenience — it is the fix for a scaffold that used to poison the very next build.
 *
 * The previous stub typed the parameter `handle(object $command): mixed` with a "replace `object` with the
 * concrete Command class" note. `object` is a BUILTIN type, so Firefly\Cqrs\Scanner\HandlerScanner, which
 * infers a bare #[CommandHandler]'s message type from handle()'s sole parameter, hit its
 * `! $type instanceof ReflectionNamedType || $type->isBuiltin()` guard and threw
 * CqrsConfigurationException("Cannot infer the message type for [...]"). HandlerScanner is called
 * unconditionally by ManifestCacheWriter::writeManifests(), and nothing there catches: the throw escaped
 * `firefly:cache` and aborted the WHOLE compile part-way through, so a developer who ran
 * `make:firefly-handler` and then `firefly:cache` — the documented next step — got no handler manifest, no
 * event/message/scheduled/security/transactional manifests, and no proxies either. The old stub test only
 * string-matched the template for `#[CommandHandler]`, which is why it never noticed; the replacement test
 * generates from the stub and runs the real scanner over the result.
 *
 * Generating the message alongside the handler is also the shape the framework's own fixtures use
 * (RegisterWidget + RegisterWidgetHandler, CountWidgets + CountWidgetsHandler): a message DTO must live in
 * its own PSR-4 file to be autoloadable, so it cannot simply be appended to the handler's file.
 */
final class MakeHandlerCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:firefly-handler';

    /** @var string */
    protected $description = 'Create a Firefly #[CommandHandler] and its command class (or #[QueryHandler] with --query).';

    /** @var string */
    protected $type = 'Firefly handler';

    /**
     * Set for the duration of the message-class emission so buildClass() picks the message stub instead of
     * the handler stub. GeneratorCommand offers no per-call stub argument — buildClass() always asks
     * getStub() — so this one-field override is the seam for writing a second file through the parent's
     * own namespace/class replacement machinery rather than re-implementing it.
     */
    private ?string $messageStub = null;

    protected function getStub(): string
    {
        if ($this->messageStub !== null) {
            return $this->messageStub;
        }

        return __DIR__.'/../../../stubs/'.($this->option('query') ? 'query-handler.stub' : 'command-handler.stub');
    }

    /**
     * Writes the handler (parent) and then its message class. A false return from the parent means it
     * refused — reserved name, or the handler already exists — and in that case nothing else is written,
     * so a re-run never drops a stray message DTO next to code it did not generate.
     */
    public function handle(): ?bool
    {
        if (parent::handle() === false) {
            return false;
        }

        $this->writeMessageClass();

        return null;
    }

    /**
     * Emits the message DTO next to the handler, in the same namespace. An existing file is left ALONE and
     * reported: the developer has almost certainly already filled it with real properties, and the handler
     * that was just generated references it by name either way, so reusing it is the correct outcome.
     */
    private function writeMessageClass(): void
    {
        $name = $this->qualifiedMessageClass();
        $path = $this->getPath($name);

        if ($this->files->exists($path)) {
            $this->components->info(sprintf('Firefly handler message [%s] already exists; reusing it.', $path));

            return;
        }

        $this->messageStub = __DIR__.'/../../../stubs/'.($this->option('query') ? 'query-message.stub' : 'command-message.stub');

        try {
            $this->makeDirectory($path);
            $this->files->put($path, $this->sortImports($this->buildClass($name)));
        } finally {
            $this->messageStub = null;
        }

        $this->components->info(sprintf('Firefly handler message [%s] created successfully.', $path));
    }

    /**
     * Substitutes `{{ message }}` — the message class' SHORT name, since handler and message share a
     * namespace and need no import — on top of the parent's `{{ class }}` replacement. Harmless when
     * building the message class itself: the message stubs carry no `{{ message }}` placeholder.
     *
     * MUST keep both parameters UNTYPED. The parent declares `replaceClass($stub, $name)` with no parameter
     * types, and PHP's contravariance rule requires an override's parameters to be at least as broad — a
     * native `string` here would be a FATAL at class-load time (the same trap MakeControllerCommand's
     * getDefaultNamespace() documents).
     *
     * @param  string  $stub
     * @param  string  $name
     */
    protected function replaceClass($stub, $name): string
    {
        $stub = parent::replaceClass($stub, $name);
        $segments = explode('\\', $this->qualifiedMessageClass());

        return str_replace(['{{ message }}', '{{message}}'], (string) end($segments), $stub);
    }

    /**
     * The message class' fully-qualified name, derived from the handler name the developer typed so that a
     * nested `make:firefly-handler Widget/RegisterWidgetHandler` puts both files in the same sub-namespace.
     *
     * A trailing "Handler" is stripped (RegisterWidgetHandler -> RegisterWidget, DemoQueryHandler ->
     * DemoQuery); without that suffix there is nothing to strip, so "Command"/"Query" is appended instead
     * (RegisterWidget -> RegisterWidgetCommand) — the two names must differ or the handler and its message
     * would fight over one file.
     */
    private function qualifiedMessageClass(): string
    {
        $segments = explode('\\', str_replace('/', '\\', ltrim($this->getNameInput(), '\\/')));
        $short = (string) array_pop($segments);

        $segments[] = str_ends_with($short, 'Handler') && strlen($short) > strlen('Handler')
            ? substr($short, 0, -strlen('Handler'))
            : $short.($this->option('query') ? 'Query' : 'Command');

        return $this->qualifyClass(implode('\\', $segments));
    }

    /** @return list<array{0: string, 1: string|null, 2: int, 3: string}> */
    protected function getOptions(): array
    {
        return [
            ['query', null, InputOption::VALUE_NONE, 'Generate a #[QueryHandler] instead of a #[CommandHandler].'],
        ];
    }
}
