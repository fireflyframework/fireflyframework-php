<?php

// tests/BannerAssetTest.php

declare(strict_types=1);

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
 * actually names it — because the way these rot is that a theme key is renamed and the asset is orphaned.
 */
it('ships a logo and a favicon drawn from the banner, and wires both into the site', function () {
    $root = dirname(__DIR__);
    $mkdocs = (string) file_get_contents($root.'/mkdocs.yml');

    foreach (['larafly-logo.svg', 'larafly-favicon.svg'] as $asset) {
        $path = $root.'/docs/assets/'.$asset;

        expect(is_file($path))->toBeTrue("missing {$asset}")
            ->and(simplexml_load_string((string) file_get_contents($path)))->not->toBeFalse("malformed {$asset}")
            ->and(str_contains($mkdocs, 'assets/'.$asset))->toBeTrue("{$asset} is not referenced by mkdocs.yml");

        $markup = (string) file_get_contents($path);

        expect($markup)->not->toContain('<script')
            ->and($markup)->not->toContain('<image')
            ->and($markup)->not->toContain('@font-face');
    }

    expect(is_file($root.'/docs/assets/stylesheets/larafly.css'))->toBeTrue('missing larafly.css')
        ->and(str_contains($mkdocs, 'assets/stylesheets/larafly.css'))->toBeTrue('larafly.css is not in extra_css');
});
