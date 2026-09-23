<?php

// tests/DocsDiagramsTest.php

declare(strict_types=1);

use Firefly\Container\Attributes\Order;
use Firefly\Observability\Web\HttpExchangeFilter;
use Firefly\Observability\Web\MetricsFilter;
use Firefly\Observability\Web\TracingFilter;
use Firefly\Security\OAuth2\Client\Web\OAuth2AuthorizationRequestRedirectFilter;
use Firefly\Security\OAuth2\Client\Web\OAuth2LoginAuthenticationFilter;
use Firefly\Security\OAuth2\OAuth2ResourceServerFilter;
use Firefly\Security\OAuth2\Server\Web\OAuth2AuthorizationServerFilter;
use Firefly\Security\Session\SecurityContextPersistenceFilter;
use Firefly\Security\Web\Basic\HttpBasicFilter;
use Firefly\Security\Web\CsrfFilter;
use Firefly\Security\Web\HttpSecurityFilter;
use Firefly\Security\Web\JwtAuthenticationFilter;
use Firefly\Security\Web\Login\FormLoginFilter;
use Firefly\Security\Web\Logout\LogoutFilter;
use Firefly\Security\Web\RememberMe\RememberMeAuthenticationFilter;
use Firefly\Security\Web\SecurityHeadersFilter;
use Firefly\Web\Filter\CorrelationIdFilter;
use Firefly\Web\Filter\RequestContextFilter;

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

/**
 * book/art/figures is the book build's own copy of the same hand-authored SVGs — `book/build/md.py`'s
 * `::: figure` directive INLINES the file it names, so the book cannot reference `docs/assets/diagrams`
 * across the toolchain boundary and carries its own set instead. Two copies of one picture is a licence to
 * drift: an `#[Order]` corrected in one tree and not the other leaves the book shipping a diagram that is
 * quietly wrong, and nothing about a green PHP suite or a successful `book/build/run.sh` would say so.
 *
 * This guard is what makes the duplication safe. The two directories must hold the SAME set of files with
 * BYTE-IDENTICAL contents, and every one of them must actually be referenced by a `::: figure` line in both
 * language trees — an unreferenced figure is a file nobody renders and therefore nobody proofreads, which is
 * how the stale copy gets shipped in the first place.
 */
it('mirrors every docs diagram into book/art/figures and references it from both language trees', function () {
    $root = dirname(__DIR__);

    $docs = array_map('basename', glob($root.'/docs/assets/diagrams/*.svg') ?: []);
    $book = array_map('basename', glob($root.'/book/art/figures/*.svg') ?: []);
    sort($docs);
    sort($book);

    expect($book)->toBe($docs, 'book/art/figures and docs/assets/diagrams hold different sets of SVGs');

    $chapters = [];
    foreach (['src', 'src-es'] as $tree) {
        $blob = '';
        foreach ((array) glob($root.'/book/'.$tree.'/**/*.md') as $md) {
            $blob .= (string) file_get_contents((string) $md);
        }
        foreach ((array) glob($root.'/book/'.$tree.'/*.md') as $md) {
            $blob .= (string) file_get_contents((string) $md);
        }
        $chapters[$tree] = $blob;
    }

    foreach ($docs as $name) {
        expect(file_get_contents($root.'/book/art/figures/'.$name))
            ->toBe(
                file_get_contents($root.'/docs/assets/diagrams/'.$name),
                "book/art/figures/{$name} has drifted from docs/assets/diagrams/{$name}"
            );

        foreach ($chapters as $tree => $blob) {
            expect(str_contains($blob, '::: figure art/figures/'.$name.' |'))
                ->toBeTrue("{$name} is in book/art/figures but no ::: figure line in book/{$tree} renders it");
        }
    }
});

/**
 * The filter-chain diagram's load-bearing content is sixteen integers transcribed by hand, and the table in
 * docs/modules/security.md repeats thirteen of them. Nothing about moving `#[Order(-83)]` on
 * `RememberMeAuthenticationFilter` would fail a test otherwise — the capstone suite's ordering test hand-feeds
 * its own numbers through a synthetic ComponentDescriptor, so it proves FilterChainRegistrar SORTS and proves
 * nothing at all about what the real classes declare.
 *
 * This guard closes that gap from the only source that cannot go stale: the attributes themselves. It reads
 * each filter's `#[Order]` by reflection, sorts the list exactly as `FilterChainRegistrar::orderedFilters()`
 * does (order ascending, ties broken by `strcmp` on the fully-qualified class name, the two unordered
 * framework filters prepended), and asserts the SVG's rows and the module doc's table are that same sequence,
 * value for value. A moved `#[Order]`, a filter added to the chain, or a row transcribed wrongly is now a red
 * test rather than a picture that lies.
 */
it('pins the filter-chain diagram and the security table to the real #[Order] attributes', function () {
    $root = dirname(__DIR__);

    // Prepended by FilterChainRegistrar itself, never sorted: they carry no #[Order] and no stereotype.
    $prepended = [RequestContextFilter::class, CorrelationIdFilter::class];

    // Every DISCOVERED filter the diagram draws. firefly/observability's three are in the picture but not in
    // the security module's table, which is that package's own filters only — hence the second list below.
    $discovered = [
        CsrfFilter::class,
        FormLoginFilter::class,
        HttpBasicFilter::class,
        HttpExchangeFilter::class,
        HttpSecurityFilter::class,
        JwtAuthenticationFilter::class,
        LogoutFilter::class,
        MetricsFilter::class,
        OAuth2AuthorizationRequestRedirectFilter::class,
        OAuth2AuthorizationServerFilter::class,
        OAuth2LoginAuthenticationFilter::class,
        OAuth2ResourceServerFilter::class,
        RememberMeAuthenticationFilter::class,
        SecurityContextPersistenceFilter::class,
        SecurityHeadersFilter::class,
        TracingFilter::class,
    ];
    $observability = [HttpExchangeFilter::class, MetricsFilter::class, TracingFilter::class];

    // The attributes themselves, read once: this map — not a number typed into this file — is what the
    // diagram and the table are then checked against.
    $orders = [];
    $shortNames = [];
    foreach ($prepended as $class) {
        $shortNames[$class] = (new ReflectionClass($class))->getShortName();
        expect((new ReflectionClass($class))->getAttributes(Order::class))
            ->toBe([], "{$class} now carries an #[Order]; the diagram calls it unordered and prepended");
    }
    foreach ($discovered as $class) {
        $shortNames[$class] = (new ReflectionClass($class))->getShortName();
        $attributes = (new ReflectionClass($class))->getAttributes(Order::class);
        expect($attributes)->toHaveCount(1, "{$class} carries no single #[Order] attribute");
        $orders[$class] = $attributes[0]->newInstance()->order;
    }

    // Exactly FilterChainRegistrar::orderedFilters()' comparator, on the real orders.
    $sorted = $discovered;
    usort($sorted, static fn (string $a, string $b): int => $orders[$a] <=> $orders[$b] ?: strcmp($a, $b));

    /** @var list<array{string, string}> $expectedRows */
    $expectedRows = [];
    foreach ($prepended as $class) {
        $expectedRows[] = ['prepend', $shortNames[$class]];
    }
    foreach ($sorted as $class) {
        $expectedRows[] = [(string) $orders[$class], $shortNames[$class]];
    }

    // Each row of the diagram is a badge <text x="79"> carrying the order, immediately followed by a
    // <text x="118"> carrying the class name. Reading the pairs in document order gives the drawn chain.
    $svg = (string) file_get_contents($root.'/docs/assets/diagrams/security-filter-chain.svg');
    preg_match_all('/<text x="79"[^>]*>([^<]*)<\/text>\s*<text x="118"[^>]*>([^<]*)<\/text>/', $svg, $matches, PREG_SET_ORDER);

    $drawnRows = array_map(
        static fn (array $row): array => [trim((string) $row[1]), trim((string) $row[2])],
        $matches,
    );
    expect($drawnRows)->toBe(
        $expectedRows,
        'security-filter-chain.svg no longer draws the chain the #[Order] attributes describe',
    );

    // The module doc's own table: this package's filters, in the same order, one `| <order> | `Name` |` row
    // each (the OAuth2 rows carry a trailing package note, which the pattern deliberately tolerates).
    $doc = (string) file_get_contents($root.'/docs/modules/security.md');
    $section = preg_split('/^### Filter order$/m', $doc)[1] ?? '';
    expect($section)->not->toBe('', 'docs/modules/security.md has no "### Filter order" section');
    $section = (string) (preg_split('/^## /m', $section)[0] ?? $section);

    /** @var list<array{string, string}> $tableRows */
    $tableRows = [];
    foreach (explode("\n", $section) as $line) {
        if (preg_match('/^\|\s*(-?\d+)\s*\|\s*`([A-Za-z0-9]+)`[^|]*\|$/', trim($line), $row) === 1) {
            $tableRows[] = [$row[1], $row[2]];
        }
    }

    /** @var list<array{string, string}> $expectedTable */
    $expectedTable = [];
    foreach ($sorted as $class) {
        if (! in_array($class, $observability, true)) {
            $expectedTable[] = [(string) $orders[$class], $shortNames[$class]];
        }
    }

    expect($tableRows)->toBe(
        $expectedTable,
        "docs/modules/security.md's filter-order table no longer matches the #[Order] attributes",
    );
});
