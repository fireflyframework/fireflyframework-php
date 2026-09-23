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
});
