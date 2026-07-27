<?php

declare(strict_types=1);

namespace Firefly\Installer;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'new', description: 'Create a new LaraFly application')]
final class NewCommand extends Command
{
    public function __construct(private readonly ?ProcessRunner $runner = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'The name/path of the new application')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Install the latest dev release of the family')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Scaffold even if the target directory is not empty')
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialise a git repository (default)')
            ->addOption('no-git', null, InputOption::VALUE_NONE, 'Skip git initialisation');
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

        if ($this->directoryIsNotEmpty($directory) && ! $input->getOption('force')) {
            $io->error("Application directory \"{$name}\" already exists. Use --force to overwrite.");

            return self::FAILURE;
        }

        $io->title('Creating a new LaraFly application');

        $create = ['composer', 'create-project', 'firefly/skeleton', $directory, '--no-interaction'];
        if ($input->getOption('dev')) {
            $create[] = '--stability=dev';
        }
        if ($runner->run($create) !== 0) {
            $io->error('composer create-project failed.');

            return self::FAILURE;
        }

        if (! $input->getOption('no-git')) {
            $runner->run(['git', 'init', '-q'], $directory);
            $runner->run(['git', 'add', '.'], $directory);
            $runner->run(['git', 'commit', '-q', '-m', 'Initial commit'], $directory);
        }

        $io->success("LaraFly application ready at {$directory}");
        $io->writeln("  cd {$name}");
        $io->writeln('  php artisan firefly:serve');

        return self::SUCCESS;
    }

    private function directoryIsNotEmpty(string $directory): bool
    {
        if (! is_dir($directory)) {
            return false;
        }
        $entries = scandir($directory);

        return $entries !== false && count($entries) > 2; // more than '.' and '..'
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }
}
