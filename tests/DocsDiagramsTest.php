<?php

// tests/DocsDiagramsTest.php

declare(strict_types=1);

use Firefly\Container\Attributes\Order;
use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\TransactionalAdviceSource;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Observability\Web\HttpExchangeFilter;
use Firefly\Observability\Web\MetricsFilter;
use Firefly\Observability\Web\TracingFilter;
use Firefly\Security\Access\Method\MethodSecurityAdviceSource;
use Firefly\Security\Access\Method\MethodSecurityInterceptor;
use Firefly\Security\Access\Method\SecurityMethodDescriptor;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Web\OAuth2AuthorizationRequestRedirectFilter;
use Firefly\Security\OAuth2\Client\Web\OAuth2LoginAuthenticationFilter;
use Firefly\Security\OAuth2\OAuth2ResourceServerFilter;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
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
use Firefly\Security\Web\Settings\FormLoginSettings;
use Firefly\Web\Filter\CorrelationIdFilter;
use Firefly\Web\Filter\RequestContextFilter;

it('ships eight well-formed, referenced SVG diagrams', function () {
    $root = dirname(__DIR__);
    $svgs = [
        'boot-pipeline.svg', 'di-autoconfig.svg', 'request-lifecycle.svg',
        'outbox-flow.svg', 'cqrs-eda-bridge.svg', 'security-filter-chain.svg',
        'oauth2-authorization-code.svg', 'method-interceptor-chain.svg',
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

    // The list above is exhaustive on purpose: a ninth SVG dropped into the directory without a line here
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
 *
 * THE RULE THIS IMPOSES ON WHOEVER ADDS THE NEXT DIAGRAM: the copy into `book/art/figures/` and the two
 * `::: figure` lines — one in `book/src/`, one in `book/src-es/` — belong in the SAME commit. A commit that
 * lands the file and defers the chapters leaves this test red, and the way out is the two figure lines, never
 * a narrower assertion here: scoping the both-trees check to diagrams "already referenced somewhere" would
 * licence exactly the unreferenced, unproofread copy the guard exists to prevent. Deferring is not free
 * either — `docs/assets/README.md` owes the new file a provenance row on the same commit (the test above),
 * and both copies have to be byte-identical from the first commit, not reconciled later.
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
                ->toBeTrue(
                    "{$name} is in book/art/figures but no ::: figure line in book/{$tree} renders it — add "
                    ."`::: figure art/figures/{$name} | <caption>` to the chapter it belongs to, in THIS commit; "
                    .'a figure the manuscript never renders is one nobody proofreads'
                );
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
 * value for value.
 *
 * The SECOND half of this test carries the same claim to the second picture. oauth2-authorization-code.svg
 * draws five of these integers, docs/modules/security-oauth2-{client,server}.md and both book chapters repeat
 * four of them in sentences, and it draws six endpoint PATHS besides — a default moved in a constructor is
 * exactly as silent as a moved `#[Order]`. Every one of those copies is checked against the attributes and the
 * settings objects themselves, never against a number or a path typed into this file.
 *
 * The two lists below are themselves PINNED TO THE TREE, and that is what makes "a filter added to the chain"
 * part of the claim true rather than aspirational. A hand-typed roster is precisely the thing this file exists
 * to distrust: the SVG set is globbed and compared, the provenance rows are globbed and compared,
 * book/art/figures is globbed and compared, and so — in the block right below the two lists — is every
 * concrete `WebFilter` class under every package's `src`. A new filter landing in any package is a red test
 * naming it, not a diagram that quietly stopped being the whole chain. So: a moved `#[Order]`, a filter added to the
 * chain, or a row transcribed wrongly is now a red test rather than a picture that lies.
 */
it('pins both diagrams, the security table and the OAuth2 prose to the real #[Order] attributes and endpoint defaults', function () {
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

    // Exhaustive by construction, not by care. Every CONCRETE class under packages/*/src that reaches the
    // WebFilter interface — directly, or through OncePerRequestFilter, or through any base a later package
    // introduces — must appear in one of the two lists above. The inheritance is resolved from the sources
    // themselves (a fixed point over `extends`/`implements`) rather than by autoloading each candidate: a
    // package whose optional dependency is absent must not turn this guard into a fatal error.
    /** @var array<string, array{parent: string|null, interfaces: list<string>, concrete: bool}> $declared */
    $declared = [];
    foreach ((array) glob($root.'/packages/*/src', GLOB_ONLYDIR) as $src) {
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator((string) $src, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walk as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $matched = preg_match(
                '/^(?<mods>(?:final |abstract |readonly )*)class (?<name>\w+)'
                .'(?: extends (?<parent>[^\s{]+))?(?: implements (?<implements>[^{]+))?/m',
                (string) file_get_contents($file->getPathname()),
                $class,
            );
            if ($matched !== 1) {
                continue;
            }

            $parent = trim($class['parent'] ?? '');
            $interfaces = [];
            foreach (explode(',', $class['implements'] ?? '') as $interface) {
                $interface = trim($interface);
                if ($interface !== '') {
                    $interfaces[] = basename(str_replace('\\', '/', $interface));
                }
            }

            $declared[$class['name']] = [
                'parent' => $parent === '' ? null : basename(str_replace('\\', '/', $parent)),
                'interfaces' => $interfaces,
                'concrete' => ! str_contains($class['mods'], 'abstract'),
            ];
        }
    }

    /** @var array<string, true> $reachesWebFilter */
    $reachesWebFilter = [];
    do {
        $grew = false;
        foreach ($declared as $name => $info) {
            if (isset($reachesWebFilter[$name])) {
                continue;
            }
            $parent = $info['parent'];
            if (in_array('WebFilter', $info['interfaces'], true) || ($parent !== null && isset($reachesWebFilter[$parent]))) {
                $reachesWebFilter[$name] = true;
                $grew = true;
            }
        }
    } while ($grew);

    $inTree = [];
    foreach ($declared as $name => $info) {
        if ($info['concrete'] && isset($reachesWebFilter[$name])) {
            $inTree[] = $name;
        }
    }
    sort($inTree);

    $drawn = array_map(
        static fn (string $class): string => (new ReflectionClass($class))->getShortName(),
        array_merge($prepended, $discovered),
    );
    sort($drawn);

    expect($inTree)->toBe(
        $drawn,
        'packages/*/src holds a different set of concrete WebFilters than security-filter-chain.svg draws: '
        .'added '.(implode(', ', array_diff($inTree, $drawn)) ?: 'nothing')
        .'; removed '.(implode(', ', array_diff($drawn, $inTree)) ?: 'nothing'),
    );

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

    // ── The authorization-code figure, and the sentences that repeat it ─────────────────────────────────────
    //
    // security-filter-chain.svg is no longer the only picture whose load-bearing content is #[Order] integers
    // transcribed by hand: oauth2-authorization-code.svg draws five of them, and the two OAuth2 module pages
    // and both book chapters repeat four in prose. What makes those copies checkable is the SPELLING RULE the
    // docs already follow — an order is written immediately after the class that declares it, in parentheses,
    // `FormLoginFilter` (`-92`), never as a bare number a reader cannot attribute to anything. So the PAIR is
    // what is asserted: containment of `(-92)` on its own would survive two filters trading orders, which is
    // the likeliest way a hand-transcribed set of five goes wrong, and it would leave the diagram's unattributed
    // integers — the ones a reader has no way to check — looking pinned when they are not.
    $pairs = [];
    foreach ($orders as $class => $order) {
        $pairs[$shortNames[$class]] = $order;
    }

    // Every file that spells one of these pairs out, with the pairs it MUST spell out. The figure is listed
    // once: the book's byte-identical copy of it is the previous test's business. docs/modules/security.md
    // demands nothing here — its table is checked above — but it is scanned like the rest, because the second
    // assertion in the loop holds every file to every pair it happens to write, listed or not.
    $repeats = [
        'docs/assets/diagrams/oauth2-authorization-code.svg' => [
            FormLoginFilter::class,
            HttpSecurityFilter::class,
            OAuth2AuthorizationRequestRedirectFilter::class,
            OAuth2AuthorizationServerFilter::class,
            OAuth2LoginAuthenticationFilter::class,
        ],
        'docs/modules/security-oauth2-client.md' => [
            HttpSecurityFilter::class,
            OAuth2AuthorizationRequestRedirectFilter::class,
            OAuth2LoginAuthenticationFilter::class,
        ],
        'docs/modules/security-oauth2-server.md' => [OAuth2AuthorizationServerFilter::class],
        'book/src/10-security.md' => [
            HttpSecurityFilter::class,
            OAuth2AuthorizationRequestRedirectFilter::class,
            OAuth2AuthorizationServerFilter::class,
            OAuth2LoginAuthenticationFilter::class,
        ],
        'book/src-es/10-security.md' => [
            HttpSecurityFilter::class,
            OAuth2AuthorizationRequestRedirectFilter::class,
            OAuth2AuthorizationServerFilter::class,
            OAuth2LoginAuthenticationFilter::class,
        ],
        'docs/modules/security.md' => [],
    ];

    foreach ($repeats as $relative => $classes) {
        // One normalised form to look for in six places: prose wraps the pair in backticks and sometimes bold,
        // an image's alt text carries no markup at all, and `#[Order(-82)]` is the same claim spelled as the
        // attribute. All three become `Name (-82)` before anything is asserted.
        $plain = str_replace(['`', '**'], '', (string) file_get_contents($root.'/'.$relative));
        $plain = (string) preg_replace('/#\[Order\((-?\d+)\)\]/', '($1)', $plain);

        foreach ($classes as $class) {
            expect(str_contains($plain, $shortNames[$class].' ('.$orders[$class].')'))->toBeTrue(
                "{$relative} no longer pairs {$shortNames[$class]} with the order it declares "
                ."({$orders[$class]}) — write the number next to the class, as `{$shortNames[$class]}` "
                ."(`{$orders[$class]}`), so a reader can attribute it and this test can pin it",
            );
        }

        // And every pair the file DOES write — including ones no list above demands — has to be the real one.
        preg_match_all('/(\w+Filter) \((-?\d+)\)/', $plain, $written, PREG_SET_ORDER);
        foreach ($written as $pair) {
            $name = (string) $pair[1];
            if (! array_key_exists($name, $pairs)) {
                continue; // a parenthesised number after something that is not one of our filters
            }

            expect((int) $pair[2])->toBe(
                $pairs[$name],
                "{$relative} writes {$name} ({$pair[2]}), but that filter's #[Order] is {$pairs[$name]}",
            );
        }
    }

    // The same figure also draws six endpoint PATHS, and those are defaults too. `new` with no arguments IS
    // the documented default for all three settings objects — the constructor signature is where every
    // `firefly.security.*` endpoint key's fallback is written — so `/oauth2/authorize` renamed in
    // AuthorizationServerSettings, a base URI moved in OAuth2ClientSettings, or `login_page` moved in
    // FormLoginSettings turns the picture red here instead of leaving a reader typing a path the framework
    // stopped answering on.
    $client = new OAuth2ClientSettings;
    $server = new AuthorizationServerSettings;
    $formLogin = new FormLoginSettings;
    $figure = (string) file_get_contents($root.'/docs/assets/diagrams/oauth2-authorization-code.svg');

    // Each one is looked for WITH the neighbour the figure draws next to it — the `/{id}` segment, the verb,
    // the trailing space before the query parameters — so that no path can pass by being a prefix of another:
    // `/oauth2/authorize` is not `/oauth2/authorization/{id}`, and a default renamed into the other's shape
    // would otherwise satisfy a bare `str_contains`.
    $drawnPaths = [
        $client->authorizationEndpointBaseUri.'/{id}' => 'OAuth2ClientSettings::$authorizationEndpointBaseUri',
        $client->redirectionEndpointBaseUri.'/{id}?code=' => 'OAuth2ClientSettings::$redirectionEndpointBaseUri',
        $server->authorizationEndpoint.' ' => 'AuthorizationServerSettings::$authorizationEndpoint',
        'POST '.$server->tokenEndpoint => 'AuthorizationServerSettings::$tokenEndpoint',
        'GET '.$server->jwkSetEndpoint => 'AuthorizationServerSettings::$jwkSetEndpoint',
        // Drawn TWICE — the entry point's 302 in the relying party's lane, and the authorization server's own
        // sign-in in the far lane — because the one default serves both halves. Both draws are pinned, so a
        // rename that updates one picture-half and forgets the other is still red.
        '302 → '.$formLogin->loginPage => 'FormLoginSettings::$loginPage',
        "the server's own ".$formLogin->loginPage => 'FormLoginSettings::$loginPage',
    ];

    foreach ($drawnPaths as $drawn => $source) {
        expect(str_contains($figure, (string) $drawn))->toBeTrue(
            "oauth2-authorization-code.svg no longer draws '{$drawn}', the default {$source} declares",
        );
    }
});

/**
 * oauth2-authorization-code.svg exists to teach ONE distinction — which legs of a sign-in the user agent carries
 * and which two it never sees — and its own footer says so outright. In a three-lane picture that distinction is
 * not carried by any word: it is carried by where the arrows GO. A front-channel hop drawn as a straight
 * relying-party → authorization-server line reads as a back-channel call however the boxes are labelled, and
 * tells the reader that `state`, the `nonce` and the S256 `code_challenge` never traverse the browser. That is
 * the inverse of the property the figure is for, and it is also a factual claim about the code:
 * `OAuth2AuthorizationRequestRedirectFilter::doFilter()` ends in `new RedirectResponse(...)` — a 302 answered to
 * whoever asked, which on the start URL is the browser — exactly as `AuthorizationEndpoint` ends in the 302 the
 * return leg already draws through the browser lane.
 *
 * So the routing is asserted geometrically, against the lanes the file itself declares. Every arrow must either
 * stay inside one lane, or touch the Browser lane at one end; the only exceptions are the two back-channel calls,
 * and even those must be drawn the way a back-channel call is drawn here — horizontally, between two boxes that
 * sit on the same row — rather than as a diagonal hop between consecutive steps. Nothing below types a coordinate:
 * the lane bounds come from the `<rect>`s, the steps from the circled numbers, the sentence from the footer.
 *
 * The circled numbers are checked as a sequence for the same reason. A step inserted in the middle of the picture
 * renumbers every box after it AND the two sentences underneath, and a renumbering that stops halfway is the
 * quietest way this figure goes wrong — the arrows stay right and the prose starts pointing at the wrong boxes.
 */
it('routes every front-channel leg of the authorization-code figure through the browser lane', function () {
    $root = dirname(__DIR__);
    $raw = (string) file_get_contents($root.'/docs/assets/diagrams/oauth2-authorization-code.svg');
    $xml = simplexml_load_string($raw);

    if (! $xml instanceof SimpleXMLElement) {
        throw new RuntimeException('malformed SVG oauth2-authorization-code.svg');
    }

    $attribute = static function (SimpleXMLElement $node, string $name): string {
        $value = $node[$name];
        if ($value === null) {
            throw new RuntimeException("<{$node->getName()}> in oauth2-authorization-code.svg has no {$name} attribute");
        }

        return (string) $value;
    };

    // The swimlanes are the direct <rect> children painted in the lane fill; the remaining direct <rect> is the
    // white page behind them. Sorted left to right they are Browser, Relying party, Authorization server — the
    // order the three <text> headings above them are written in, and the order every assertion below assumes.
    $lanes = [];
    foreach ($xml->rect as $rect) {
        $fill = $rect['fill'];
        if ($fill === null || (string) $fill !== '#f8fafc') {
            continue;
        }

        $left = (float) $attribute($rect, 'x');
        $lanes[] = [$left, $left + (float) $attribute($rect, 'width')];
    }

    usort($lanes, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    expect($lanes)->toHaveCount(3, 'oauth2-authorization-code.svg no longer draws exactly three swimlanes');

    $browserLane = 0;
    $laneNames = ['the Browser lane', 'the Relying party lane', 'the Authorization server lane'];

    $laneOf = static function (float $x) use ($lanes): int {
        foreach ($lanes as $index => [$from, $to]) {
            if ($x >= $from && $x <= $to) {
                return $index;
            }
        }

        throw new RuntimeException("an arrow endpoint at x={$x} lands in no swimlane at all");
    };

    // The circled step numbers, in document order — which for this figure is top to bottom. Each one is the
    // <text text-anchor="middle"> drawn at its circle's centre; the two counterpart boxes in the authorization
    // server's lane carry no circle, because they are the far end of a call rather than a step of their own.
    $numbered = [];
    foreach ($xml->g->text as $text) {
        if ($text['text-anchor'] === null) {
            continue;
        }

        $numbered[(float) $attribute($text, 'x').'|'.(float) $attribute($text, 'y')] = trim((string) $text);
    }

    $steps = [];
    foreach ($xml->g->circle as $circle) {
        $cx = (float) $attribute($circle, 'cx');
        $cy = (float) $attribute($circle, 'cy');
        $key = $cx.'|'.($cy + 4);

        expect(array_key_exists($key, $numbered))->toBeTrue(
            "the step circle at ({$cx}, {$cy}) in oauth2-authorization-code.svg has no number drawn in it",
        );

        $steps[] = ['number' => $numbered[$key], 'x' => $cx, 'y' => $cy];
    }

    expect(array_column($steps, 'number'))->toBe(
        array_map(strval(...), range(1, count($steps))),
        'oauth2-authorization-code.svg numbers its steps out of sequence — a box was inserted or removed and the '
        .'renumbering stopped halfway, which leaves the two sentences under the figure pointing at the wrong boxes',
    );

    // ── The routing itself ──────────────────────────────────────────────────────────────────────────────────
    $backChannel = [];
    foreach ($xml->line as $line) {
        $from = $laneOf((float) $attribute($line, 'x1'));
        $to = $laneOf((float) $attribute($line, 'x2'));

        if ($from === $to || $from === $browserLane || $to === $browserLane) {
            continue; // inside one lane, or with the user agent at one end: a front channel drawn honestly
        }

        $y1 = (float) $attribute($line, 'y1');
        $y2 = (float) $attribute($line, 'y2');

        expect($y1)->toBe($y2, sprintf(
            'oauth2-authorization-code.svg draws a DIAGONAL arrow from %s to %s, skipping %s. Consecutive steps '
            .'sit on consecutive rows, so a sloped line between those two lanes is a front-channel hop drawn as '
            .'if it were server-to-server — it tells the reader that state, the nonce and the S256 code_challenge '
            .'never traverse the user agent. They do: the relying party answers a 302 and the BROWSER follows it.',
            $laneNames[$from],
            $laneNames[$to],
            $laneNames[$browserLane],
        ));

        $backChannel[] = $y1;
    }

    expect($backChannel)->toHaveCount(2, sprintf(
        'oauth2-authorization-code.svg joins the relying party to the authorization server directly %d time(s); '
        .'exactly two legs of this flow may skip the Browser lane — the token exchange and the JWKS fetch — and '
        .'the figure says so in its own footer.',
        count($backChannel),
    ));

    // And the footer's sentence names those two by number, so the renumbering above cannot leave it stale.
    $named = [];
    foreach ($backChannel as $y) {
        foreach ($steps as $step) {
            if (abs($step['y'] - $y) < 0.5 && $step['x'] >= $lanes[1][0] && $step['x'] <= $lanes[1][1]) {
                $named[] = (int) $step['number'];

                break;
            }
        }
    }

    sort($named);
    expect($named)->toHaveCount(2, 'a back-channel arrow in oauth2-authorization-code.svg leaves no numbered step');
    expect(str_contains($raw, "Steps {$named[0]} and {$named[1]} are back-channel calls"))->toBeTrue(
        "oauth2-authorization-code.svg's footer no longer names steps {$named[0]} and {$named[1]} as the "
        .'back-channel pair, but those are the two arrows it draws straight between the relying party and the '
        .'authorization server',
    );
});

/**
 * method-interceptor-chain.svg has exactly the property that made the other two figures dangerous: its
 * load-bearing content is a pair of integers — the advice orders `100` and `1000` — transcribed by hand, and
 * this repository now spells them out in SEVEN places. The figure draws both (twice each: in the AdviceSource
 * boxes and again on the interceptor badges), `book/art/figures` holds a byte-identical second copy of the
 * file, both language chapters repeat them in prose AND in the figure caption, `docs/modules/transactional.md`
 * writes them in the `Advice` bullet and again in the image's alt text, `docs/modules/security.md` writes them
 * where it sends the reader here, and `docs/assets/README.md`'s provenance row writes them while promising the
 * picture was drawn from the source. Nothing above this test looks at a single one of them: the roster in
 * the first test buys the new figure four structural checks — well-formed, one `<title>`/`<desc>`, embedded
 * somewhere, mirrored into the book — and none of those would notice a `100` that should read `250`.
 *
 * So the same treatment the filter chain and the authorization-code figure already get. The source of truth
 * is not an `#[Order]` attribute here but the `Advice` each `AdviceSource` returns — the very object
 * `ProxyPlanner` sorts the sources by and `InterceptorRegistry` resolves an interceptor for — and NO integer
 * is typed into this file: every number asserted against a document is interpolated from the reflected advice,
 * so moving an order in code turns this test red and names the documents that still spell the old one.
 *
 * The badges are read as a SEQUENCE, the way the filter chain's rows are, because the ordering is the whole
 * claim the picture makes. Two advices trading orders while both documents keep saying `100` and `1000` would
 * satisfy containment of either number on its own, and it is exactly the invariant the chapter sells: lower
 * runs outer, so a refusal is thrown before a transaction is ever opened.
 */
it('pins the interceptor-chain figure and its prose to the Advice each AdviceSource declares', function () {
    $root = dirname(__DIR__);

    // The two shipped advices, taken from the sources themselves rather than reconstructed here: these are the
    // objects ProxyPlanner sorts, ProxyClassGenerator names members from, and InterceptorRegistry resolves.
    $security = (new MethodSecurityAdviceSource)->advice();
    $transactional = (new TransactionalAdviceSource)->advice();

    expect($security->id)->toBe(MethodSecurityAdviceSource::ID)
        ->and($security->interceptorClass)->toBe(MethodSecurityInterceptor::class)
        ->and($security->descriptorClass)->toBe(SecurityMethodDescriptor::class)
        ->and($security->inertWhenUnbound)->toBeTrue(
            'the figure and both chapters say security is the advice that goes inert when its interceptor bean '
            .'is absent, because it exists only under firefly.security.enabled',
        )
        ->and($transactional->id)->toBe(Advice::TRANSACTIONAL)
        ->and($transactional->interceptorClass)->toBe(TransactionInterceptor::class)
        ->and($transactional->descriptorClass)->toBe(TransactionalDescriptor::class)
        ->and($transactional->inertWhenUnbound)->toBeFalse(
            'the figure draws the transactional advice as one that fails the boot when its interceptor has '
            .'vanished — the fail-loud default every advice but security keeps',
        );

    // The one sentence the whole picture exists to make true.
    expect($security->order)->toBeLessThan(
        $transactional->order,
        'method security no longer runs OUTSIDE the transaction: the figure, both chapters and both module '
        .'pages all claim a refusal is thrown before a transaction is ever opened',
    );

    // Short names come from the classes the advices NAME, so a class renamed or moved is a red test too.
    $securityInterceptor = (new ReflectionClass($security->interceptorClass))->getShortName();
    $securityDescriptor = (new ReflectionClass($security->descriptorClass))->getShortName();
    $transactionalInterceptor = (new ReflectionClass($transactional->interceptorClass))->getShortName();
    $transactionalDescriptor = (new ReflectionClass($transactional->descriptorClass))->getShortName();
    $adviceSource = (new ReflectionClass(MethodSecurityAdviceSource::class))->getShortName();
    $securityOrder = (string) $security->order;
    $transactionalOrder = (string) $transactional->order;

    // Exactly ProxyPlanner's comparator, on the real advices: order ascending, ties broken by id.
    $chain = [$security, $transactional];
    usort($chain, static fn (Advice $a, Advice $b): int => $a->order <=> $b->order ?: strcmp($a->id, $b->id));

    /** @var list<array{string, string}> $expectedChain */
    $expectedChain = array_map(
        static fn (Advice $advice): array => [
            (string) $advice->order,
            (new ReflectionClass($advice->interceptorClass))->getShortName(),
        ],
        $chain,
    );

    // Each link of the runtime half of the figure is an order badge — a `<text x="187">` inside a small rect —
    // immediately followed by the `<text x="236">` naming the interceptor that runs there. Reading the pairs in
    // document order gives the chain the picture draws, top to bottom, which is the order MethodInvocation
    // walks the list in.
    $svg = (string) file_get_contents($root.'/docs/assets/diagrams/method-interceptor-chain.svg');
    preg_match_all(
        '/<text x="187"[^>]*>order (\d+)<\/text>\s*<text x="236"[^>]*>([A-Za-z]+) /',
        $svg,
        $badges,
        PREG_SET_ORDER,
    );

    $drawnChain = array_map(
        static fn (array $badge): array => [trim((string) $badge[1]), trim((string) $badge[2])],
        $badges,
    );
    expect($drawnChain)->toBe(
        $expectedChain,
        'method-interceptor-chain.svg no longer draws the chain the shipped Advice orders describe',
    );

    // And every other number the figure writes next to the word "order" is one of those two — the AdviceSource
    // boxes at the top and the <desc> a screen reader hears both repeat them, and neither is covered above.
    preg_match_all('/\border (\d+)\b/', $svg, $written, PREG_SET_ORDER);
    expect(count($written))->toBeGreaterThan(count($expectedChain), 'method-interceptor-chain.svg writes no order outside its badges');
    foreach ($written as $occurrence) {
        expect(in_array((string) $occurrence[1], [$securityOrder, $transactionalOrder], true))->toBeTrue(
            "method-interceptor-chain.svg writes 'order {$occurrence[1]}', which is neither shipped advice's order "
            ."({$securityOrder} for {$security->id}, {$transactionalOrder} for {$transactional->id})",
        );
    }

    // The rest of what the figure spells out about the two advices, each fragment built from the advice rather
    // than typed: the descriptor and interceptor class names the boxes name, the inert flag, and the member
    // names the generated proxy carries — which are Advice::property()/factory() output, not a convention a
    // reader could re-derive.
    $figureFragments = [
        "advice(): Advice('{$security->id}', {$securityInterceptor},",
        "{$securityDescriptor}, order {$securityOrder}, inertWhenUnbound: "
            .($security->inertWhenUnbound ? 'true' : 'false').')',
        "Advice('{$transactional->id}',",
        "{$transactionalInterceptor}, {$transactionalDescriptor}, order {$transactionalOrder})",
        'private MethodInterceptor $'.$security->property().';',
        'private MethodInterceptor $'.$transactional->property().';',
        'static '.$security->factory()."('m') / ".$transactional->factory()."('m')",
        '[$this-&gt;'.$security->property().', $this-&gt;'.$transactional->property().'],',
        $securityDescriptor.'::class =&gt; self::'.$security->factory()."('transfer')",
    ];

    foreach ($figureFragments as $fragment) {
        expect(str_contains($svg, $fragment))->toBeTrue(
            "method-interceptor-chain.svg no longer draws '{$fragment}', which is what the shipped advices declare",
        );
    }

    // The prose copies. Each fragment carries the neighbouring words the document writes the number WITH, for
    // the same two reasons the OAuth2 paths are pinned with theirs: a bare `100` is a prefix of `1000`, and an
    // unattributed integer is one a reader cannot check either. Rewording a sentence is therefore a deliberate
    // visit to this list — which is the point, because the sentence is the claim.
    $prose = [
        'book/src/09-transactions.md' => [
            $adviceSource."'s advice is {$securityOrder} and the transactional one is {$transactionalOrder},",
            "advice order {$securityOrder} before the transaction at {$transactionalOrder}.",
        ],
        'book/src-es/09-transactions.md' => [
            "el advice de {$adviceSource} es {$securityOrder} y el transaccional es {$transactionalOrder},",
            "orden de advice {$securityOrder} antes que la transacción en {$transactionalOrder}.",
        ],
        'docs/modules/transactional.md' => [
            "The transactional advice is {$transactionalOrder} and security's is {$securityOrder},",
            "advice order {$securityOrder} before the transaction at order {$transactionalOrder}]",
        ],
        'docs/modules/security.md' => [
            "{$securityInterceptor} runs at advice order {$securityOrder}, "
                ."ahead of the transactional link at {$transactionalOrder},",
        ],
        // The provenance row is a claim about the picture, so it repeats the integers too — and it is the row
        // the README's own preamble points at when it promises every diagram was "drawn directly from the
        // shipped source, not invented".
        'docs/assets/README.md' => [
            "{$securityInterceptor} at advice order {$securityOrder} "
                ."outside {$transactionalInterceptor} at {$transactionalOrder},",
        ],
    ];

    foreach ($prose as $relative => $fragments) {
        // One normalised form for five files with three different wrapping habits: prose backticks and bolds
        // the numbers, an image's alt text carries no markup at all, and docs/modules/*.md hard-wraps its
        // paragraphs — so a sentence there is split across lines that this test must not have an opinion about.
        $plain = str_replace(['`', '**'], '', (string) file_get_contents($root.'/'.$relative));
        $plain = trim((string) preg_replace('/\s+/', ' ', $plain));

        foreach ($fragments as $fragment) {
            expect(str_contains($plain, $fragment))->toBeTrue(
                "{$relative} no longer writes '{$fragment}' — the advice orders it repeats are "
                ."{$securityOrder} for {$security->id} and {$transactionalOrder} for {$transactional->id}",
            );
        }
    }
});
