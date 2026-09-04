<?php

declare(strict_types=1);

namespace Firefly\Installer;

use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `firefly new <app>` — the LaraFly project generator, the Spring Initializr analog.
 *
 * It wraps `composer create-project firefly/skeleton`, then shapes the result into the requested archetype
 * (see ArchetypeApplier) and git-inits it. Every external process goes through the ProcessRunner seam so
 * the whole flow is assertable without a network.
 */
#[AsCommand(name: 'new', description: 'Create a new LaraFly application')]
final class NewCommand extends Command
{
    /** The interactive picker's explicit opt-out; never a capability id. */
    private const string NO_CAPABILITIES = 'none';

    public function __construct(private readonly ?ProcessRunner $runner = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'The name/path of the new application')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Install the latest dev release of the family')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Empty the target directory first, then scaffold into it')
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialise a git repository (default)')
            ->addOption('no-git', null, InputOption::VALUE_NONE, 'Skip git initialisation')
            ->addOption('api', null, InputOption::VALUE_NONE, 'Archetype: '.Archetype::Api->summary())
            ->addOption('web', null, InputOption::VALUE_NONE, 'Archetype (default): '.Archetype::Web->summary())
            ->addOption('full', null, InputOption::VALUE_NONE, 'Archetype: '.Archetype::Full->summary())
            ->addOption(
                'with',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                // Interpolated from the catalog rather than written out, so `--help` can never drift from
                // what `--with=` actually accepts.
                'Comma-separated capabilities to pre-wire: '.implode(', ', CapabilityCatalog::ids()),
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $runner = $this->runner ?? new SymfonyProcessRunner($output);

        $name = $input->getArgument('name');
        if (! is_string($name) || $name === '') {
            $answer = $io->ask('What is the name of your application?', 'my-app');
            $name = is_string($answer) ? $answer : 'my-app';
        }

        $cwd = getcwd();
        if (! is_string($cwd)) {
            $io->error('Could not resolve the working directory.');

            return self::FAILURE;
        }
        $directory = match (true) {
            $name === '.' => $cwd,
            $this->isAbsolutePath($name) => $name,
            default => $cwd.'/'.$name,
        };

        if (($status = $this->ensureTargetIsUsable($io, $input, $directory, $name)) !== null) {
            return $status;
        }

        try {
            $archetype = $this->resolveArchetype($io, $input);
            $capabilities = $this->resolveCapabilities($io, $input, $archetype);
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $io->title('Creating a new LaraFly application');
        // Plain text rather than definitionList()/block(): SymfonyStyle wraps and pads block output to the
        // terminal width, which turns a long absolute path into two half-lines. A generated project's own
        // directory is the one string in this summary the user is most likely to want to copy.
        $io->text([
            'Directory     '.$directory,
            'Archetype     '.$archetype->value.' — '.$archetype->summary(),
            'Capabilities  '.($capabilities === []
                ? 'none (add them later with composer require)'
                : implode(', ', array_map(static fn (Capability $c): string => $c->id, $capabilities))),
        ]);
        $io->newLine();

        $create = ['composer', 'create-project', 'firefly/skeleton', $directory, '--no-interaction'];
        if ($input->getOption('dev')) {
            $create[] = '--stability=dev';
        }
        if ($runner->run($create) !== 0) {
            $io->error('composer create-project failed.');

            return self::FAILURE;
        }

        $notes = (new ArchetypeApplier($archetype, $capabilities))->applyTo($directory);
        if ($notes !== []) {
            $io->section('Applied the '.$archetype->value.' archetype');
            $io->listing($notes);
        }

        if ($archetype->reshapesFiles()) {
            // The skeleton's post-create-project-cmd ends in `php artisan firefly:cache`, so the compiled
            // manifests describe the project as create-project left it — including the controller the
            // archetype has just pruned. ArchetypeApplier already deleted those artifacts (an app with no
            // manifests boots by scanning and is always correct); this puts the COMPILED path back, which
            // is the one the skeleton hands the user. A failure here is not fatal: the app is scanned, and
            // firefly:serve now says so and names the command that fixes it.
            if ($runner->run(['php', 'artisan', 'firefly:cache'], $directory) !== 0) {
                $io->warning('Could not recompile the manifests; the app will boot by scanning. Run `php artisan firefly:cache` when convenient.');
            }
        }

        if (! $input->getOption('no-git')) {
            $runner->run(['git', 'init', '-q'], $directory);
            $runner->run(['git', 'add', '.'], $directory);
            $runner->run(['git', 'commit', '-q', '-m', 'Initial commit'], $directory);
        }

        $io->success("LaraFly application ready at {$directory}");
        $io->writeln("  cd {$name}");
        if ($capabilities !== []) {
            // The archetype only WROTE the capability requires; create-project had already resolved the
            // skeleton's own by then, so vendor/ does not hold them yet. Re-resolving here would mean a
            // second full Composer run inside `firefly new` for a command the user can see and skip.
            $io->writeln('  composer update              # install the capabilities the archetype added');
        }
        $io->writeln('  php artisan firefly:serve');

        return self::SUCCESS;
    }

    /**
     * Guard — and, under --force, actually clear — the target directory.
     *
     * THE BUG THIS REPLACES: the old code skipped its OWN "directory not empty" error when --force was set
     * and then shelled straight into `composer create-project`, which refuses a non-empty target on its own
     * ("Project directory ... is not empty."). Composer has no --force for create-project, so --force could
     * never do anything but turn a clear installer error into a confusing composer one. There is no flag to
     * reach for; the only honest implementation is to empty the directory ourselves first, and to say so
     * before doing it.
     *
     * @return int|null a status code to return from execute(), or null to continue
     */
    private function ensureTargetIsUsable(SymfonyStyle $io, InputInterface $input, string $directory, string $name): ?int
    {
        if (! Filesystem::directoryIsNotEmpty($directory)) {
            return null;
        }

        if (! $input->getOption('force')) {
            $io->error("Application directory \"{$name}\" already exists. Use --force to overwrite.");

            return self::FAILURE;
        }

        if (Filesystem::isProtectedPath($directory)) {
            $io->error("Refusing to empty \"{$directory}\": it is a filesystem root or your home directory.");

            return self::FAILURE;
        }

        $io->warning('--force: everything below will be permanently deleted.');
        $io->writeln('  '.$directory);
        $io->newLine();
        // Defaults to yes so that --force stays meaningful under --no-interaction (Symfony returns the
        // default without asking when the input is not interactive) while an interactive run still gets to
        // read the path before agreeing to lose it.
        if (! $io->confirm('Continue?', true)) {
            $io->writeln('Aborted.');

            return self::FAILURE;
        }

        if (! Filesystem::emptyDirectory($directory)) {
            $io->error("Could not empty \"{$directory}\" — check the permissions and try again.");

            return self::FAILURE;
        }

        return null;
    }

    /**
     * @throws InvalidArgumentException when more than one archetype flag is passed
     */
    private function resolveArchetype(SymfonyStyle $io, InputInterface $input): Archetype
    {
        $flagged = Archetype::fromFlags([
            Archetype::Api->value => (bool) $input->getOption('api'),
            Archetype::Web->value => (bool) $input->getOption('web'),
            Archetype::Full->value => (bool) $input->getOption('full'),
        ]);
        if ($flagged instanceof Archetype) {
            return $flagged;
        }

        if (! $input->isInteractive()) {
            return Archetype::Web;
        }

        $choices = [];
        foreach (Archetype::cases() as $case) {
            $choices[$case->value] = $case->summary();
        }
        $answer = $io->choice('Which application shape?', $choices, Archetype::Web->value);

        return Archetype::from(is_string($answer) ? $answer : Archetype::Web->value);
    }

    /**
     * The archetype's own capabilities merged with `--with=` (or, when neither was given and the terminal
     * is interactive, with whatever the picker returns). Under --no-interaction with no flags the answer is
     * the archetype's default and nothing is ever asked.
     *
     * @return list<Capability>
     *
     * @throws InvalidArgumentException on an unknown capability id
     */
    private function resolveCapabilities(SymfonyStyle $io, InputInterface $input, Archetype $archetype): array
    {
        $requested = $this->parseWith($input);
        $askable = $requested === [] && $archetype !== Archetype::Full && $input->isInteractive();

        if ($askable) {
            // 'none' is a real choice rather than "just press enter", because Symfony's multi-select
            // ChoiceQuestion validates the DEFAULT through the same regex as any typed answer and rejects
            // the empty string outright ('Value "" is invalid'). An explicit opt-out is also the clearer
            // prompt: "which capabilities?" with no visible way to answer "none" reads like a trap.
            $choices = [self::NO_CAPABILITIES => 'Just the framework — add capabilities later'];
            foreach (CapabilityCatalog::all() as $id => $capability) {
                $choices[$id] = $capability->summary;
            }
            $answer = $io->choice('Which capabilities?', $choices, self::NO_CAPABILITIES, multiSelect: true);
            foreach (is_array($answer) ? $answer : [$answer] as $picked) {
                if (is_string($picked) && $picked !== '' && $picked !== self::NO_CAPABILITIES) {
                    $requested[] = strtolower(trim($picked));
                }
            }
        }

        $ids = array_map(static fn (Capability $c): string => $c->id, $archetype->capabilities());

        return CapabilityCatalog::resolve([...$ids, ...$requested]);
    }

    /**
     * `--with=security,eda` and `--with=security --with=eda` are the same thing: VALUE_IS_ARRAY collects the
     * repeats, and each value is split on commas.
     *
     * @return list<string>
     */
    private function parseWith(InputInterface $input): array
    {
        $raw = $input->getOption('with');
        if (! is_array($raw)) {
            $raw = $raw === null ? [] : [$raw];
        }

        $ids = [];
        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }
            foreach (explode(',', $value) as $id) {
                $id = strtolower(trim($id));
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }
}
