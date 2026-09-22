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
