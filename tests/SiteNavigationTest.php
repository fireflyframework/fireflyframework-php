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
