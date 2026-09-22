<?php

declare(strict_types=1);

/**
 * A module document is only documentation if a reader can reach it.
 *
 * Two things rot independently of the prose. A package README (and, in this repository, more than one class
 * docblock) points at `docs/modules/<name>.md` as "the long version" — a promise that is free to make and
 * silent to break, because nothing loads a README. And a document that IS written can still be invisible:
 * MkDocs builds its navigation from the `nav:` list in `mkdocs.yml` alone, so a file nobody added there is
 * published without ever being linked, and the two hand-maintained tables that send people into the docs —
 * the README's module table and `docs/index.md`'s — are edited by hand at the end of a wave, which is exactly
 * when a wave is out of budget.
 *
 * Both halves were live when this was written: `packages/security-oauth2-server/README.md` and
 * `Firefly\Cli\Command\OAuth2KeysCommand` had promised `docs/modules/security-oauth2-server.md` for twelve
 * commits before it existed. The assertion is deliberately about REACHABILITY and not about content: a
 * module document's own quality is not something a test can hold, but "the file a package promises exists"
 * and "every module document is in the navigation and both tables" are.
 */
it('ships the module document every package README points at', function () {
    $root = dirname(__DIR__);
    $missing = [];

    foreach (glob($root.'/packages/*/README.md') ?: [] as $readme) {
        preg_match_all('#docs/modules/[a-z0-9-]+\.md#', (string) file_get_contents($readme), $matches);

        foreach (array_unique($matches[0]) as $target) {
            if (! is_file($root.'/'.$target)) {
                $missing[] = basename(dirname($readme)).' → '.$target;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('links every module document from the navigation, the README table and the documentation index', function () {
    $root = dirname(__DIR__);
    $nav = (string) file_get_contents($root.'/mkdocs.yml');
    $readme = (string) file_get_contents($root.'/README.md');
    $index = (string) file_get_contents($root.'/docs/index.md');

    $unreachable = [];

    foreach (glob($root.'/docs/modules/*.md') ?: [] as $document) {
        $name = basename($document);

        if (! str_contains($nav, 'modules/'.$name)) {
            $unreachable[] = $name.' is not in the mkdocs navigation';
        }
        if (! str_contains($readme, 'docs/modules/'.$name)) {
            $unreachable[] = $name.' is not in the README module table';
        }
        if (! str_contains($index, 'modules/'.$name)) {
            $unreachable[] = $name.' is not in docs/index.md';
        }
    }

    expect($unreachable)->toBe([]);
});
