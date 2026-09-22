<?php

// tests/BannerAssetTest.php

declare(strict_types=1);

/*
 * The three brand assets — the banner, the header logo and the favicon — and the two properties that
 * docs/assets/README.md promises about them.
 *
 * The first is that each one is still *wired in*: the way an asset like this rots is that a theme key is
 * renamed, or a landing page is rewritten, and the file is orphaned with nothing going red. Which consumer
 * has to name it differs per asset, so that check is per-asset — `mkdocs.yml` for the logo and the favicon,
 * `README.md` and `docs/index.md` for the banner, which `mkdocs.yml` never mentions at all.
 *
 * The second is that each one is *self-contained*, and that property is identical for all three, so it is
 * stated once over the whole set in the last test.
 */

it('ships a well-formed banner embedded in the README and the docs landing', function () {
    $root = dirname(__DIR__);
    $banner = $root.'/docs/assets/larafly-banner.svg';
    expect(is_file($banner))->toBeTrue('missing larafly-banner.svg');
    expect(simplexml_load_string((string) file_get_contents($banner)))->not->toBeFalse('malformed banner SVG');

    expect((string) file_get_contents($root.'/README.md'))->toContain('larafly-banner.svg')
        ->and((string) file_get_contents($root.'/docs/index.md'))->toContain('larafly-banner.svg');
});

/**
 * The banner was the only brand asset with a guard, and the site now depends on two more: a header logo and
 * a favicon, both drawn as their own SVGs from the banner's firefly glyph rather than cropped out of it. The
 * assertion is the same shape as the banner's — the file exists, it parses, and the place that consumes it
 * actually names it — except that for these two the consumer is `mkdocs.yml`, whose `theme.logo` and
 * `theme.favicon` keys are the only things pointing at them.
 */
it('ships a logo and a favicon drawn from the banner, and wires both into the site', function () {
    $root = dirname(__DIR__);
    $mkdocs = (string) file_get_contents($root.'/mkdocs.yml');

    foreach (['larafly-logo.svg', 'larafly-favicon.svg'] as $asset) {
        $path = $root.'/docs/assets/'.$asset;

        expect(is_file($path))->toBeTrue("missing {$asset}")
            ->and(simplexml_load_string((string) file_get_contents($path)))->not->toBeFalse("malformed {$asset}")
            ->and(str_contains($mkdocs, 'assets/'.$asset))->toBeTrue("{$asset} is not referenced by mkdocs.yml");
    }

    expect(is_file($root.'/docs/assets/stylesheets/larafly.css'))->toBeTrue('missing larafly.css')
        ->and(str_contains($mkdocs, 'assets/stylesheets/larafly.css'))->toBeTrue('larafly.css is not in extra_css');
});

/**
 * Self-containment is what makes these three render identically on GitHub, on Packagist and in the built
 * site, and it is the property docs/assets/README.md states outright ("no external reference of any kind").
 * It is also the easiest one to lose by accident: a single `<image href="https://...">` dropped into the
 * banner, or an `@font-face` added to pin the wordmark's typeface instead of leaving it to whatever the
 * reader has installed, and the file quietly needs the network — with every other check here still green.
 *
 * So the guard is the general form of the promise rather than a list of the tags that happen to break it
 * today: after the one URL an SVG is allowed to carry — its own namespace — no scheme and no
 * protocol-relative reference may be left anywhere in the markup.
 */
it('keeps every brand asset free of external references', function (string $asset) {
    $markup = (string) file_get_contents(dirname(__DIR__).'/docs/assets/'.$asset);

    expect($markup)->not->toContain('<script')
        ->and($markup)->not->toContain('<image')
        ->and($markup)->not->toContain('@font-face');

    $withoutNamespace = str_replace('http://www.w3.org/2000/svg', '', $markup);

    expect($withoutNamespace)->not->toContain('http')
        ->and($withoutNamespace)->not->toContain('//');
})->with(['larafly-banner.svg', 'larafly-logo.svg', 'larafly-favicon.svg']);
