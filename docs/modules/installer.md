# Installer

The installer is bundled in the `firefly/firefly` library. Its `firefly new` Symfony Console binary
uses the skeleton shipped in that same distribution, so creating an application needs no skeleton mirror.

## `firefly new <app>`

```bash
composer global require firefly/firefly
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
   composer create-project firefly/skeleton <directory> --no-interaction --repository=<bundled-path-repository> [--stability=dev]
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

## Distribution

The root manifest exposes `packages/installer/bin/firefly` as a Composer binary. Its autoloader supports
both Composer's installed proxy and direct execution in a source checkout. The installer reads the kernel
version for its bundled skeleton path repository; Deptrac permits that one framework dependency.
`--with=testing` also declares the external test harness in the generated application's development
requirements, because replacing `firefly/testing` does not install its old transitive requirements.

## The `ProcessRunner` seam

<!-- source: packages/installer/src/ProcessRunner.php -->
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
