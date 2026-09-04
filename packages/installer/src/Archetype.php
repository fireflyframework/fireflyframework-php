<?php

declare(strict_types=1);

namespace Firefly\Installer;

use InvalidArgumentException;

/**
 * The project shape `firefly new` generates — the Spring Initializr "project type" row.
 *
 * There is exactly ONE skeleton package on Packagist (firefly/skeleton) and archetypes are applied to the
 * directory `composer create-project` produced, not chosen at create-project time. That is a deliberate
 * trade-off. The alternative — firefly/skeleton-api, firefly/skeleton-web, firefly/skeleton-full — means
 * three packages to keep in lockstep, three release pipelines, and three copies of every skeleton fix; the
 * three shapes differ by four files and a require block, which is far less than they share. Shaping after
 * the fact keeps one skeleton authoritative, and the shaping is small enough to assert file-by-file
 * (see ArchetypeTest, which drives it against the REAL skeleton in the monorepo).
 */
enum Archetype: string
{
    case Api = 'api';
    case Web = 'web';
    case Full = 'full';

    public function summary(): string
    {
        return match ($this) {
            self::Api => 'JSON only — the sample #[RestController], no view layer, no welcome page',
            self::Web => 'HTML + JSON — the #[Controller] welcome page and the sample #[RestController]',
            self::Full => 'HTML + JSON plus every optional capability pre-wired',
        };
    }

    /**
     * Paths, relative to the generated project root, that this archetype deletes.
     *
     * The welcome test goes with the welcome page on purpose: leaving `test_the_welcome_page_renders_html`
     * behind in a project whose welcome page has just been deleted hands the user a red suite on the first
     * `composer test`, which is a worse first impression than no test at all. The api archetype replaces it
     * (see self::stubs()) with the two cases that survive.
     *
     * @return list<string>
     */
    public function prunes(): array
    {
        return match ($this) {
            self::Api => [
                'app/Http/WelcomeController.php',
                'resources/views/welcome.blade.php',
                'tests/Feature/WelcomeTest.php',
            ],
            self::Web, self::Full => [],
        };
    }

    /**
     * Files this archetype writes into the generated project: relative target path => absolute source.
     *
     * The api smoke test is the only stub the installer owns. It is coupled to the skeleton's `Tests\`
     * namespace and to the sample controller's `/greetings/{name}` route; ArchetypeTest pins both against
     * the real skeleton so the coupling breaks a build rather than a user's first run.
     *
     * The source carries a `.stub` suffix (the Laravel generator convention) because packages/ is a PHPSTAN
     * ANALYSIS ROOT: a real .php file here referencing Tests\TestCase and $this->getJson() would be analysed
     * as installer source and fail level max on classes that only exist inside a generated app.
     *
     * @return array<string, string>
     */
    public function stubs(): array
    {
        $stubs = dirname(__DIR__).'/stubs';

        return match ($this) {
            self::Api => ['tests/Feature/ApiSmokeTest.php' => $stubs.'/api/tests/Feature/ApiSmokeTest.php.stub'],
            self::Web, self::Full => [],
        };
    }

    /**
     * A project-relative file each stub NEEDS in order to compile: stub target => prerequisite.
     *
     * This is not defensive padding. `skeleton/.gitattributes` marks `/tests export-ignore`, so a real
     * `composer create-project firefly/skeleton` ships NO tests/ directory at all — no tests/TestCase.php,
     * and therefore nothing for `namespace Tests\Feature; ... extends TestCase` to extend. Copying the stub
     * in regardless turned the generated api project's first `composer test` from the web baseline's
     * "Test directory tests/Feature not found" (exit 2) into a hard `Class "Tests\TestCase" not found`
     * fatal (exit 255) — strictly worse than adding nothing. Writing a test whose base class is absent is
     * never the right move, so the stub lands only where it can actually run; if the skeleton ever ships
     * its tests/ again, the prerequisite is satisfied and the stub comes back with no change here.
     *
     * @return array<string, string>
     */
    public function stubPrerequisites(): array
    {
        return match ($this) {
            self::Api => ['tests/Feature/ApiSmokeTest.php' => 'tests/TestCase.php'],
            self::Web, self::Full => [],
        };
    }

    /**
     * True when applying this archetype adds or removes files, i.e. when the compiled manifests
     * `composer create-project` already wrote (the skeleton's post-create-project-cmd ends in
     * `php artisan firefly:cache`) no longer describe what is on disk.
     */
    public function reshapesFiles(): bool
    {
        return $this->prunes() !== [] || $this->stubs() !== [];
    }

    /**
     * The capabilities this archetype pre-wires before `--with=` is merged on top.
     *
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Api, self::Web => [],
            self::Full => CapabilityCatalog::full(),
        };
    }

    /**
     * The single archetype the given flag set selects.
     *
     * @param  array<string, bool>  $flags  archetype value => whether its flag was passed
     *
     * @throws InvalidArgumentException when more than one archetype flag is set
     */
    public static function fromFlags(array $flags): ?self
    {
        $selected = array_keys(array_filter($flags));
        if (count($selected) > 1) {
            throw new InvalidArgumentException(sprintf(
                'Pick one archetype: --%s are mutually exclusive.',
                implode(' / --', $selected),
            ));
        }

        return $selected === [] ? null : self::from($selected[0]);
    }
}
