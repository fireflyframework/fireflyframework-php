<?php

declare(strict_types=1);

namespace Firefly\Cli\Command\Make;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * `make:firefly-listener` — scaffolds an #[EventListener] method by default, or a #[MessageListener]
 * with `--message` (pyfly's `generate listener` parity, split by transport via one flag).
 *
 * Both stubs now put a #[Component] stereotype on the class. Without it the generated listener was not a
 * bean at all: ComponentScanner::describe() returns null for a class carrying no #[Component]-derived
 * attribute, so the class never reached the component manifest and the container held no definition for
 * it. Both #[EventListener] and #[MessageListener] are documented as marking "a public BEAN method", and
 * EventListenerWiringPass / MessageListenerWiringPass resolve `$container->make($descriptor->class)` fresh
 * on every delivery precisely so the fully post-processed bean is used. An unregistered class survives that
 * make() only by falling through to Illuminate's reflective auto-build, which produces a plain object
 * outside Firefly's lifecycle — no #[Value] injection, no bean post-processing, no #[Transactional] proxy,
 * a brand-new instance per message rather than the singleton the app configured. The listener appeared to
 * work in a hello-world and quietly lost half its wiring as soon as it depended on anything.
 */
final class MakeListenerCommand extends GeneratorCommand
{
    /** @var string */
    protected $name = 'make:firefly-listener';

    /** @var string */
    protected $description = 'Create a Firefly #[EventListener] (or #[MessageListener] with --message).';

    /** @var string */
    protected $type = 'Firefly listener';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/'.($this->option('message') ? 'message-listener.stub' : 'event-listener.stub');
    }

    /** @return list<array{0: string, 1: string|null, 2: int, 3: string}> */
    protected function getOptions(): array
    {
        return [
            ['message', null, InputOption::VALUE_NONE, 'Generate a #[MessageListener] instead of an #[EventListener].'],
        ];
    }
}
