<?php

// tests/DocsDiagramsTest.php

declare(strict_types=1);

it('ships five well-formed, referenced SVG diagrams', function () {
    $root = dirname(__DIR__);
    $svgs = [
        'boot-pipeline.svg', 'di-autoconfig.svg', 'request-lifecycle.svg',
        'outbox-flow.svg', 'cqrs-eda-bridge.svg',
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
    }
});
