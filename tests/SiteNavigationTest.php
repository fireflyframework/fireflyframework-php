<?php

// tests/SiteNavigationTest.php

declare(strict_types=1);

use Firefly\Tests\Support\MkdocsConfig;

/*
 * The site's tab row and its Modules landing page, held to the mechanism that actually produces them.
 *
 * This file exists because the first version of that wiring was explained wrongly in `mkdocs.yml`, and the
 * explanation read entirely plausible: it said the bare `- modules.md` under `Modules:` was what Material's
 * `navigation.indexes` bound to the section, so the tab opened the landing page. Every visible symptom
 * agreed — the feature was listed, the entry was there, the tab did open the landing page — and the sentence
 * was still false. `navigation.indexes` fires only for a page MkDocs answers `Page::is_index` for
 * (`index.md` or `README.md`, nothing else), so on a tree with no such page it is inert: deleting it from
 * `theme.features` and rebuilding with `mkdocs build --strict` produces HTML identical to the byte apart
 * from the features array echoed into the page's own JS config. What really opens the tab is
 * `partials/tabs-item.html` linking a section to `children | first`.
 *
 * A wiring claim that reads true and is not is the failure this whole wave is about, and prose cannot be
 * held to it. So the two facts the landing page depends on are asserted instead of described:
 *
 *   - WHERE EACH TAB LANDS, computed the way Material computes it — first child, descending while that child
 *     is a section — and required to be a document that exists. Reordering a section's entries moves its
 *     tab, silently, and that is the kind of change nobody makes on purpose.
 *
 *   - THAT `navigation.indexes` IS LISTED EXACTLY WHEN IT CAN DO SOMETHING. Stated as an equivalence rather
 *     than as "it must be absent", because the other route out of the original bug is real: move the landing
 *     page to `docs/modules/index.md` and the feature starts firing. Whoever does that should find this test
 *     telling them to turn the feature on, not telling them the config they just earned is forbidden.
 */

it('opens every tab on a document that exists, and the Modules tab on the modules landing', function () {
    $targets = MkdocsConfig::tabTargets();

    expect($targets)->not->toBeEmpty('mkdocs.yml has no navigation at all');

    $missing = [];
    foreach ($targets as $tab => $document) {
        if (! is_file(MkdocsConfig::root().'/docs/'.$document)) {
            $missing[] = "{$tab} → docs/{$document}";
        }
    }

    expect($missing)->toBe([], 'a tab points at a document that does not exist: '.implode(', ', $missing));

    expect($targets['Modules'] ?? null)->toBe(
        'modules.md',
        "the Modules tab no longer opens the landing page — Material links a section's tab to its FIRST "
        .'entry, so `- Overview: modules.md` has to stay at the top of the `Modules:` list',
    );
});

/**
 * The features the tab row and the landing page are built on, and the one that would be decoration.
 *
 * `navigation.tabs` is what turns the top level of `nav:` into the header row at all; `navigation.sections`
 * is what renders the ten groups inside Modules as headings, which is the structure `docs/modules.md`'s card
 * grid mirrors. Both are load-bearing for a reader, and neither announces its own absence — the site simply
 * collapses into a plain sidebar and still builds clean under `--strict`.
 */
it('enables the navigation features the tab row and the Modules landing are built on', function () {
    $features = MkdocsConfig::features();

    foreach (['navigation.tabs', 'navigation.sections'] as $feature) {
        expect(in_array($feature, $features, true))->toBeTrue("theme.features no longer enables {$feature}");
    }
});

it('lists navigation.indexes only when a section really has an index page', function () {
    $sections = MkdocsConfig::sectionsWithIndexPage();
    $listed = in_array('navigation.indexes', MkdocsConfig::features(), true);

    expect($listed)->toBe(
        $sections !== [],
        $sections === []
            ? 'theme.features lists navigation.indexes, but no section in nav: has an index.md/README.md '
                .'child for it to bind, so the feature does nothing — drop it, or give a section a real '
                .'index page'
            : 'these navigation sections have an index page and would be bound to it by Material, but '
                .'theme.features does not list navigation.indexes: '.implode(', ', array_unique($sections)),
    );
});

/**
 * The numbers the pages quote, against the tree they claim to count.
 *
 * `docs/modules.md` opens by telling a reader how big the framework is — "29 installable Composer packages",
 * "28 libraries plus the `firefly/firefly` runtime metapackage", "the 32 guides below" — and `docs/index.md`
 * repeats the guide count when it sends people there. Every one of those was hand-counted, and a hand-counted
 * number is the one kind of documentation that goes wrong with no edit at all: it rots when somebody adds a
 * package, which is the moment nobody is reading the introduction. This release exists partly because of that
 * failure, and it was not hypothetical here: when this test was written, `README.md`'s own module section said
 * "27 packages under `packages/*`" while that directory already held 29. Naming it was not enough — the README
 * was corrected by hand in the same wave and then left outside `$claims`, which is how the number had got
 * wrong in the first place. Its three counts are now held to the tree like the other two pages', so the file
 * that introduces the framework cannot be the one file that goes stale unnoticed.
 *
 * So the prose is asserted against `glob()` rather than proof-read. `tests/ReleaseWorkflowTest.php` already
 * does this for the publish matrix and says why at its `toHaveCount(30)`: the number is spelled out so that
 * ADDING a unit fails loudly rather than shipping something silently uncounted. The same bargain is made here,
 * and it cuts both ways on purpose — a thirty-third guide turns this red, and the fix is to write the true
 * number, which is the edit that was being forgotten.
 *
 * Whitespace is collapsed before matching because these files are hard-wrapped at about 110 columns: "lays all
 * 32 guides out by concern" is one sentence to a reader and two lines to `str_contains()`, and a guard that
 * depends on where a paragraph happens to wrap would fail the next time somebody reflows it.
 */
it('quotes package and guide counts that match the tree, on the README, the Modules landing and the front page', function () {
    $root = MkdocsConfig::root();
    $packages = count(glob($root.'/packages/*/composer.json') ?: []);
    $guides = count(glob($root.'/docs/modules/*.md') ?: []);

    $claims = [
        'docs/modules.md' => [
            '**'.$packages.' installable Composer packages**',
            ($packages - 1).' libraries plus the `firefly/firefly` runtime metapackage',
            '**'.$guides.' guides**',
        ],
        'docs/index.md' => [
            'lays all '.$guides.' guides out by concern',
        ],
        // The README says it three times — the section's opening sentence, the closing sentence under the
        // table, and the guide count that introduces the table itself — and all three are the same two
        // glob()s. `$packages + 1` is the skeleton: a `type: project` template at the top level rather than
        // under `packages/*`, which is exactly why it has to be counted separately and exactly why a
        // hand-counted total forgets it.
        'README.md' => [
            $packages.' packages under `packages/*`',
            ($packages + 1).' shippable units in total',
            'round out the '.$packages.' packages',
            'is the '.($packages + 1).'th unit',
            'The '.$guides.' [module guides]',
        ],
    ];

    $drifted = [];

    foreach ($claims as $document => $needles) {
        $prose = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($root.'/'.$document));

        foreach ($needles as $needle) {
            if (! str_contains($prose, $needle)) {
                $drifted[] = $document.' no longer says "'.$needle.'"';
            }
        }
    }

    expect($drifted)->toBe(
        [],
        'the tree now holds '.$packages.' packages and '.$guides.' module guides, and a page still quotes the '
        .'old number: '.implode('; ', $drifted),
    );
});

/**
 * The wiring sentence, against the two idioms the tree really uses.
 *
 * The first version of this paragraph said installing a package is the whole wiring step because "its
 * `#[Configuration]` class registers the defaults behind `#[ConditionalOnMissingBean]`" — one mechanism,
 * stated of the package set as a whole. The OUTCOME it promised was true everywhere; the mechanism was true
 * for 17 of the 29. `firefly/web`, the package nearly every reader installs, has no `#[Configuration]` class
 * at all: it binds its whole default set from `WebServiceProvider::register()` behind `bound()` checks, an
 * idiom its own comment calls "first-one-wins style, same idiom as every other binding in this method". A
 * reader who trusted the sentence and went looking for that class found nothing, which is the worst shape a
 * documentation error takes — not a claim that reads false, a claim that reads true and sends you somewhere
 * empty.
 *
 * That is the same drift the counts guard above exists for, one layer further in: a number rots when somebody
 * adds a package, and a mechanism named as universal rots when somebody adds a package that wires itself the
 * other way. So the split is computed rather than proof-read, and both halves are pinned to a file:
 *
 *   - THE COUNTS, from `glob()` and the attribute itself, so that adding a package — with a
 *     `#[Configuration]` class or without one — turns this red rather than quietly making "17 of the 29"
 *     into a lie no diff touched.
 *
 *   - THE TWO EXEMPLARS, because a count says how many and a reader needs somewhere to look. The page sends
 *     them to `DataAutoConfiguration` for the conditional-bean idiom and to `WebServiceProvider::register()`
 *     for the guarded-binding one, and those two files are asserted to still show what they are cited for:
 *     the first `#[Configuration]`-annotated and conditional, the second neither annotated nor unguarded.
 *     Should `firefly/web` ever grow a `#[Configuration]` class, this fails on the citation before it fails
 *     on the count, and the fix is to re-pick the example — which is exactly the edit being forgotten.
 *
 * Whitespace is collapsed for the same reason as above: the file is hard-wrapped near 110 columns, and a
 * guard that cared where a paragraph broke would go red on a reflow.
 */
it('names both wiring idioms on the Modules landing, split the way the packages really split', function () {
    $root = MkdocsConfig::root();

    $annotated = static function (string $directory): bool {
        if (! is_dir($directory)) {
            return false;
        }

        /** @var Iterator<string, SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('/^#\[Configuration\b/m', (string) file_get_contents($file->getPathname())) === 1) {
                return true;
            }
        }

        return false;
    };

    $packages = array_map(
        static fn (string $manifest): string => basename(dirname($manifest)),
        glob($root.'/packages/*/composer.json') ?: [],
    );

    $configured = array_values(array_filter(
        $packages,
        static fn (string $package): bool => $annotated($root.'/packages/'.$package.'/src'),
    ));

    expect($packages)->not->toBeEmpty('there are no packages under packages/* at all');

    // The citations, before the counts: the page sends readers to these two files by name.
    $dataAutoConfiguration = (string) file_get_contents($root.'/packages/data/src/DataAutoConfiguration.php');
    $webServiceProvider = (string) file_get_contents($root.'/packages/web/src/WebServiceProvider.php');

    expect($dataAutoConfiguration)->toMatch(
        '/^#\[Configuration\b/m',
        'packages/data/src/DataAutoConfiguration.php is cited on the Modules landing as the model '
        .'`#[Configuration]` class and is no longer annotated as one',
    );

    expect(str_contains($dataAutoConfiguration, 'ConditionalOnMissingBean'))->toBeTrue(
        'packages/data/src/DataAutoConfiguration.php is cited on the Modules landing for backing off behind '
        .'`#[ConditionalOnMissingBean]` and no longer uses it',
    );

    expect($annotated($root.'/packages/web/src'))->toBeFalse(
        'the Modules landing cites `firefly/web` as the package with NO `#[Configuration]` class, binding its '
        .'defaults behind `bound()` checks instead — it has one now, so the example has to be re-picked',
    );

    expect(substr_count($webServiceProvider, '$this->app->bound('))->toBeGreaterThan(
        0,
        'the Modules landing cites `WebServiceProvider::register()` for the `bound()`-guarded idiom, and that '
        .'provider no longer guards anything with `bound()`',
    );

    $prose = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($root.'/docs/modules.md'));

    $claims = [
        '**'.count($configured).' of the '.count($packages).' packages** carry a `#[Configuration]` class',
        '**other '.(count($packages) - count($configured)).'** have no such class',
        '`packages/data/src/DataAutoConfiguration.php`',
        '`WebServiceProvider::register()`',
        '`bound()`',
    ];

    $drifted = [];

    foreach ($claims as $needle) {
        if (! str_contains($prose, $needle)) {
            $drifted[] = 'docs/modules.md no longer says "'.$needle.'"';
        }
    }

    expect($drifted)->toBe(
        [],
        count($configured).' of the '.count($packages).' packages carry a `#[Configuration]` class and the '
        .'other '.(count($packages) - count($configured)).' do not, and the Modules landing no longer says so: '
        .implode('; ', $drifted),
    );
});
