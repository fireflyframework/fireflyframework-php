<?php

// tests/DocsDiagramsTest.php

declare(strict_types=1);

it('ships six well-formed, referenced SVG diagrams', function () {
    $root = dirname(__DIR__);
    $svgs = [
        'boot-pipeline.svg', 'di-autoconfig.svg', 'request-lifecycle.svg',
        'outbox-flow.svg', 'cqrs-eda-bridge.svg', 'security-filter-chain.svg',
    ];
    $docsBlob = '';
    foreach (glob($root.'/docs/**/*.md') ?: [] as $md) {
        $docsBlob .= (string) file_get_contents($md);
    }
    foreach (glob($root.'/docs/*.md') ?: [] as $md) {
        $docsBlob .= (string) file_get_contents($md);
    }

    foreach ($svgs as $name) {
        $path = $root.'/docs/assets/diagrams/'.$name;
        expect(is_file($path))->toBeTrue("missing {$name}");

        $xml = simplexml_load_string((string) file_get_contents($path));
        expect($xml)->not->toBeFalse("malformed SVG {$name}");

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException("malformed SVG {$name}");
        }

        expect($xml->getName())->toBe('svg', "root of {$name} is not <svg>")
            ->and(str_contains($docsBlob, $name))->toBeTrue("{$name} not embedded in any doc page");

        // A diagram is also a piece of prose for someone who cannot see it: exactly one <title> names the
        // figure and exactly one <desc> says what it shows, which is what a screen reader reads out and what
        // MkDocs' own accessibility story rests on. Two of either is as broken as none — the reader is told
        // the picture is called two different things.
        expect($xml->title->count())->toBe(1, "{$name} has no single <title>")
            ->and($xml->desc->count())->toBe(1, "{$name} has no single <desc>");
    }

    // The list above is exhaustive on purpose: a seventh SVG dropped into the directory without a line here
    // would ship unchecked — no well-formedness, no <title>/<desc>, no page embedding it, and no provenance
    // row (see the test below). Comparing the two sets is what turns adding a diagram into a deliberate act.
    $shipped = array_map('basename', glob($root.'/docs/assets/diagrams/*.svg') ?: []);
    sort($shipped);
    $expected = $svgs;
    sort($expected);
    expect($shipped)->toBe($expected, 'docs/assets/diagrams holds a different set of SVGs than this test names');
});

/**
 * docs/assets/README.md is the diagrams' provenance record. Its preamble claims outright that "Each diagram was
 * drawn directly from the shipped source, not invented — the class/file set it depicts is noted in its own
 * `<desc>` element and below", and the `Diagram | Depicts | Verified against` table under it is that "below". A
 * diagram added without its row makes the claim false for the set and leaves a picture nobody can check against
 * the code it draws, which is the one thing these hand-authored SVGs must stay answerable for.
 */
it('records every shipped diagram in the docs/assets/README.md provenance table', function () {
    $root = dirname(__DIR__);
    $readme = (string) file_get_contents($root.'/docs/assets/README.md');

    $table = preg_split('/^# Diagrams$/m', $readme)[1] ?? '';
    expect($table)->not->toBe('', 'docs/assets/README.md has no "# Diagrams" section');

    $rows = [];
    foreach (explode("\n", $table) as $line) {
        if (preg_match('/^\|\s*`([a-z0-9-]+\.svg)`\s*\|(.+)\|(.+)\|\s*$/', trim($line), $m) === 1) {
            $rows[$m[1]] = [trim($m[2]), trim($m[3])];
        }
    }

    foreach (glob($root.'/docs/assets/diagrams/*.svg') ?: [] as $svg) {
        $name = basename($svg);
        expect(array_key_exists($name, $rows))->toBeTrue("{$name} has no row in the docs/assets/README.md provenance table");
        expect($rows[$name][0])->not->toBe('', "{$name}'s provenance row says nothing under Depicts")
            ->and(str_contains($rows[$name][1], 'packages/'))
            ->toBeTrue("{$name}'s row names no package path under 'Verified against'");
    }
});
