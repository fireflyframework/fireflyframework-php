<?php

// tests/BannerAssetTest.php

declare(strict_types=1);

use Firefly\Tests\Support\MkdocsConfig;

/*
 * The three brand assets — the banner, the header logo and the favicon — and the two properties that
 * docs/assets/README.md promises about them, plus the one property the stylesheet that dresses them has to
 * keep on its own.
 *
 * The first is that each one is still *wired in*: the way an asset like this rots is that a theme key is
 * renamed, or a landing page is rewritten, and the file is orphaned with nothing going red. Which consumer
 * has to name it differs per asset, so that check is per-asset — `mkdocs.yml` for the logo, the favicon and
 * the stylesheet, `README.md` and `docs/index.md` for the banner, which `mkdocs.yml` never mentions at all.
 *
 * The second is that each one is *self-contained*, and that property is identical for all three, so it is
 * stated once over the whole set.
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
 * actually names it — except that for these two, and for the stylesheet beside them, the consumer is
 * `mkdocs.yml`, whose `theme.logo`, `theme.favicon` and `extra_css` keys are the only things pointing at them.
 *
 * "Names it" is therefore read off the parsed document rather than out of the raw text: a renamed or deleted
 * key is what this half of the test is for, and it has to be unrepresentable here, not merely unlikely.
 */
it('ships a logo and a favicon drawn from the banner, and wires both into the site', function () {
    $root = dirname(__DIR__);

    foreach (['larafly-logo.svg', 'larafly-favicon.svg'] as $asset) {
        $path = $root.'/docs/assets/'.$asset;

        expect(is_file($path))->toBeTrue("missing {$asset}")
            ->and(simplexml_load_string((string) file_get_contents($path)))->not->toBeFalse("malformed {$asset}");
    }

    $config = MkdocsConfig::load();
    $theme = MkdocsConfig::asArray($config['theme'] ?? null, 'theme');

    expect($theme['logo'] ?? null)->toBe('assets/larafly-logo.svg', 'theme.logo no longer points at larafly-logo.svg')
        ->and($theme['favicon'] ?? null)->toBe('assets/larafly-favicon.svg', 'theme.favicon no longer points at larafly-favicon.svg');

    expect(is_file($root.'/docs/assets/stylesheets/larafly.css'))->toBeTrue('missing larafly.css');

    $extraCss = MkdocsConfig::asArray($config['extra_css'] ?? null, 'extra_css');

    expect(in_array('assets/stylesheets/larafly.css', $extraCss, true))
        ->toBeTrue('larafly.css is not listed in extra_css');
});

/**
 * The palette is an interface, not an implementation detail: the `--lf-*` custom properties `larafly.css`
 * declares are what the module cards, the landing pages and every later page of this site paint with. And a
 * custom property is the one kind of CSS reference that fails *silently* — `color: var(--lf-slate-600)` on a
 * token nobody declares is not a parse error and not a warning; the declaration is simply dropped and the
 * element inherits whatever its parent had. A renamed token therefore takes its readers down with it while
 * `mkdocs build --strict` stays green and the page merely looks a little wrong.
 *
 * So the guard is closure over the file's own vocabulary: every `--lf-*` token the stylesheet *reads* must be
 * a token the stylesheet *declares*. Comments are stripped before either side is collected, because a colour
 * discussed in prose is not a colour that exists — which is the same mistake the `mkdocs.yml` assertions above
 * exist to avoid making.
 */
it('declares every LaraFly palette token the stylesheet reads', function () {
    $css = (string) file_get_contents(dirname(__DIR__).'/docs/assets/stylesheets/larafly.css');
    $rules = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    preg_match_all('/(--lf-[a-z0-9-]+)\s*:/i', $rules, $declarations);
    preg_match_all('/var\(\s*(--lf-[a-z0-9-]+)/i', $rules, $references);

    $declared = array_unique($declarations[1]);
    $read = array_unique($references[1]);
    $dangling = array_values(array_diff($read, $declared));

    expect($declared)->not->toBeEmpty('larafly.css declares no --lf-* tokens at all')
        ->and($read)->not->toBeEmpty('larafly.css reads no --lf-* tokens at all')
        ->and($dangling)->toBe([], 'larafly.css reads --lf-* tokens it never declares: '.implode(', ', $dangling));
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

// The card GitHub shows when the repository is linked from Slack, X, LinkedIn or a chat client. It is
// uploaded by hand in Settings → Social preview (GitHub exposes no API for it), so what this guards is
// that the artwork it was rendered from stays in the repository and that the PNG beside it is still the
// 1280×640 GitHub asks for — a rescaled or stale export is the failure mode nobody notices.
it('ships the social preview card as artwork and as a 1280x640 PNG', function () {
    $root = dirname(__DIR__);
    $svg = $root.'/docs/assets/larafly-social-preview.svg';
    $png = $root.'/docs/assets/larafly-social-preview.png';

    expect(is_file($svg))->toBeTrue('missing larafly-social-preview.svg')
        ->and(simplexml_load_string((string) file_get_contents($svg)))->not->toBeFalse('malformed social preview SVG')
        ->and(is_file($png))->toBeTrue('missing larafly-social-preview.png');

    $size = getimagesize($png);

    expect($size)->not->toBeFalse('unreadable social preview PNG')
        ->and($size[0] ?? null)->toBe(1280)
        ->and($size[1] ?? null)->toBe(640);
});
