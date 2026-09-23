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
 * failure, and it is not hypothetical here: `README.md`'s own module table still says "27 packages under
 * `packages/*`" while that directory holds 29. The first sentence of the page that introduces the framework is
 * the highest-visibility place left for the same drift, so it is the one place worth spending a test on.
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
it('quotes package and guide counts that match the tree, on the Modules landing and the front page', function () {
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
