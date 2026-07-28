# Installer

`firefly/installer` is LaraFly's global scaffolding tool — the `laravel/installer` analogue. It is a single
`firefly new` Symfony Console binary with **zero Firefly runtime dependencies**, so `composer global require
firefly/installer` stays a light, fast install regardless of how large the rest of the framework family gets.

## `firefly new <app>`

```bash
composer global require firefly/installer
firefly new my-app
```

Synopsis: `firefly new [name] [--dev] [--force] [--git] [--no-git]`.

- **`name`** *(optional argument)* — the target directory. If omitted, the command prompts for one
  interactively (default suggestion `my-app`). `.` scaffolds into the current directory; an absolute path is
  used as-is, otherwise it's resolved relative to the current working directory.
- **`--dev`** — passes `--stability=dev` through to the underlying `composer create-project`, installing the
  latest development snapshot of the family instead of the latest tagged release.
- **`--force`** / **`-f`** — allows scaffolding into a directory that already exists and isn't empty. Without
  it, `firefly new` refuses and exits with an error if the target directory has any entries.
- **`--git`** — initialize a git repository in the new project and make an initial commit (this is already
  the default behavior).
- **`--no-git`** — skip git initialization entirely.

On success, it prints the next two commands to run:

```
cd my-app
php artisan firefly:serve
```

## What it wraps

`firefly new` does not scaffold anything itself — it is a thin orchestrator around two well-known commands,
run via its `ProcessRunner` seam:

1. ```bash
   composer create-project firefly/skeleton <directory> --no-interaction [--stability=dev]
   ```
   Exactly the same command documented in [Installation § Without the installer](../installation.md#without-the-installer)
   — `firefly/skeleton`'s own `post-create-project-cmd` hooks (`.env` copy, sqlite file, `key:generate`,
   `firefly:cache`) run exactly the same way whether you invoke `composer create-project` yourself or go
   through `firefly new`.
2. If git initialization wasn't skipped:
   ```bash
   git init -q
   git add .
   git commit -q -m "Initial commit"
   ```
   run inside the newly created directory.

If the `composer create-project` step fails, `firefly new` reports the error and exits non-zero without
attempting git initialization.

## The zero-firefly-deps design

`packages/installer/composer.json` requires only `php`, `symfony/console`, and `symfony/process` — no
`firefly/kernel`, no `firefly/container`, nothing from the runtime family. This is deliberate: `firefly/installer`
is meant to be installed **globally** (`composer global require`), running entirely outside the context of any
particular LaraFly project, so it must not drag in framework packages it will never use. The Deptrac `Installer`
layer reflects this — it depends on no other layer in `deptrac.yaml`, and is depended on by none.

## The `ProcessRunner` seam

```php
interface ProcessRunner
{
    /**
     * @param  list<string>  $command  argv, first element is the program
     * @return int the process exit code (0 = success)
     */
    public function run(array $command, ?string $cwd = null): int;
}
```

`NewCommand` depends only on this narrow interface, never on `Symfony\Component\Process\Process` directly —
`SymfonyProcessRunner` is the real shipped implementation (streams the child process's output through the
command's own `OutputInterface`), and the test suite swaps in a fake implementation to exercise `NewCommand`'s
argument-building and control flow without ever shelling out to a real `composer`/`git` binary.

## See also

- [CLI Reference](../cli.md) — the `firefly:*` Artisan commands `firefly new` scaffolds into your new app.
- [Installation](../installation.md) — the full install-and-first-run walkthrough.
