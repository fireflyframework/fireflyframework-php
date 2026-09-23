<?php

// tests/DocsProseIsRealTest.php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\ExposureModel;
use Firefly\Config\Config;
use Firefly\Data\Exception\DriverErrorTable;
use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Repository\Locking\HasOptimisticLock;
use Firefly\Data\Repository\Locking\OptimisticLockException;
use Firefly\Kernel\Exception\Infrastructure\OptimisticLockingFailureException;
use Firefly\Security\Access\Expression\ExpressionParseException;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionRoot;
use Firefly\Web\Error\ErrorPageRenderer;
use Firefly\Web\Error\ErrorPageSettings;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

/**
 * The guard for documentation PROSE, the half `tests/DocsCodeIsRealTest.php` cannot see.
 *
 * That test compares a fenced listing against the file it names, byte for byte, which is why a wrong listing
 * cannot ship. A wrong SENTENCE can: nothing compares "produces OptimisticLockingFailureException" or
 * "unexposed (404) until named" against anything, and all three claims below shipped in exactly that shape —
 * each one a statement a reader would act on (leave an endpoint unguarded because it is "404 anyway", catch a
 * type the translator never raises, write an expression the tokenizer rejects).
 *
 * The technique is the one the diagram guard already uses: the truth is DERIVED from the framework at test
 * time — the evaluator's own parser decides which function names exist, ExposureModel's own default decides
 * which endpoints answer on a fresh install, the translator's own match decides which exceptions it builds —
 * and the documents are then held against that. Nothing here types out an answer that the source could
 * contradict, so the day the code changes, the failure names the sentence that has to change with it.
 *
 * Scope: every `docs/**.md` plus `README.md`. The triggers are deliberately narrow — a paragraph is only
 * checked when it makes the kind of exhaustive claim that can be false — so a page is free to mention
 * `hasRole()` or `/actuator/env` in passing without owing the full enumeration.
 */

/**
 * Every Markdown page the framework publishes, split into blank-line-separated paragraphs.
 *
 * `docs/superpowers/**` is git-ignored working material, never shipped, and is skipped.
 *
 * @return array<string, list<string>> repo-relative path => paragraphs
 */
function fireflyProsePages(): array
{
    $root = dirname(__DIR__);

    $paths = [$root.'/README.md'];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/docs', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'md' && ! str_contains($file->getPathname(), '/docs/superpowers/')) {
            $paths[] = $file->getPathname();
        }
    }
    sort($paths);

    $pages = [];
    foreach ($paths as $path) {
        $paragraphs = preg_split('/\n\s*\n/', (string) file_get_contents($path));
        $pages[substr($path, strlen($root) + 1)] = $paragraphs === false ? [] : $paragraphs;
    }

    return $pages;
}

it('pins every whitelist enumeration to the functions SecurityExpressionEvaluator really dispatches', function () {
    // DERIVED, not typed out. Every public bool method of the expression root is a candidate; the evaluator's
    // own parse() — which validates the name against dispatch()'s match with no root attached — is what
    // decides. Add hasIpAddress() to both and this list grows on its own, and the documents owe it a mention.
    $evaluator = new SecurityExpressionEvaluator;
    $whitelist = [];
    foreach ((new ReflectionClass(SecurityExpressionRoot::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $returnType = $method->getReturnType();
        if ($method->isConstructor() || ! $returnType instanceof ReflectionNamedType || $returnType->getName() !== 'bool') {
            continue;
        }

        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            // A string parameter takes a literal; hasPermission()'s `mixed $target` takes a #param reference.
            $arguments[] = $type instanceof ReflectionNamedType && $type->getName() === 'string' ? "'X'" : '#id';
        }

        try {
            $evaluator->parse($method->getName().'('.implode(',', $arguments).')');
        } catch (Throwable) {
            // The root offers it, the grammar does not: not part of the whitelist the documents describe.
            continue;
        }

        $whitelist[] = $method->getName();
    }

    expect($whitelist)->toContain('hasRole', 'hasAnyRole', 'hasAnyAuthority', 'hasAnyScope', 'denyAll');

    // The other direction, so "and nothing else" is a checked claim and not a hope: Spring names these and
    // this evaluator does not implement them. A document may not offer one to a reader.
    $absent = ['isAnonymous', 'isFullyAuthenticated', 'isRememberMe', 'hasIpAddress', 'principal', 'authentication'];
    foreach ($absent as $name) {
        expect(fn () => $evaluator->parse($name."('X')"))->toThrow(ExpressionParseException::class);
    }

    // A paragraph that says "whitelist tokenizer" AND starts naming functions has taken on the whole list:
    // every one of these enumerations closes with "and nothing else" / "only", so a short list is a false
    // one. A page that merely says the evaluator is a closed whitelist (docs/laravel-comparison.md) names no
    // function and owes none.
    $enumerations = 0;
    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            if (stripos($paragraph, 'whitelist tokenizer') === false) {
                continue;
            }

            $named = array_values(array_filter(
                $whitelist,
                static fn (string $name): bool => preg_match('/`'.$name.'[`(]/', $paragraph) === 1,
            ));
            if (count($named) < 2) {
                continue;
            }

            $enumerations++;
            $missing = array_values(array_diff($whitelist, $named));
            expect($missing)->toBe([], sprintf(
                '%s enumerates the expression whitelist and claims it is closed, but omits %s. '
                .'SecurityExpressionEvaluator::dispatch() accepts all of: %s.',
                $page,
                implode(', ', $missing),
                implode(', ', $whitelist),
            ));

            expect(str_contains($paragraph, '#param'))
                ->toBeTrue(sprintf('%s omits the #param references from the whitelist.', $page));

            foreach ($absent as $name) {
                expect(str_contains($paragraph, '`'.$name))->toBeFalse(sprintf(
                    '%s offers `%s` in the whitelist, and the evaluator refuses it with ExpressionParseException.',
                    $page,
                    $name,
                ));
            }
        }
    }

    // README.md, docs/architecture.md and docs/modules/security.md. If a rewrite drops one, this test would
    // otherwise pass by checking nothing at all.
    expect($enumerations)->toBe(3);
});

it('pins every 404-until-exposed claim to the endpoints ExposureModel really ships exposed', function () {
    $root = dirname(__DIR__);

    // The default include list, read off the model an application without any firefly.management config gets.
    $model = ExposureModel::fromConfig(new Config(new ConfigRepository([])));
    /** @var list<string> $defaults */
    $defaults = (new ReflectionProperty(ExposureModel::class, 'include'))->getValue($model);
    expect($defaults)->not->toBe([]);

    // Every id the framework actually mounts, read off the endpoint implementations themselves.
    $ids = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/packages', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php' || ! str_contains($file->getPathname(), '/src/')) {
            continue;
        }
        if (preg_match('/function endpointId\(\): string[^}]*?return\s+\'([a-z0-9]+)\'/s', (string) file_get_contents($file->getPathname()), $found) === 1) {
            $ids[] = $found[1];
        }
    }
    sort($ids);
    expect($ids)->toContain('health', 'info', 'env', 'beans', 'metrics', 'prometheus');

    // Exactly the default pair answers on a fresh install; everything else is a 404 until it is named.
    foreach ($ids as $id) {
        expect($model->isExposed($id))->toBe(in_array($id, $defaults, true), sprintf(
            '/actuator/%s is %s with no configuration, which the default include list (%s) contradicts.',
            $id,
            $model->isExposed($id) ? 'reachable' : 'a 404',
            implode(',', $defaults),
        ));
    }

    // A paragraph that reaches for the exposure key and says 404 must scope that 404, because it is false of
    // the default pair. Two of the four accepted scopings are BUILT from $defaults, so the day the default
    // include list changes, the documents that spell it out fail here instead of quietly going stale.
    $scopings = [
        'sensitive',
        'every other endpoint',
        '`'.implode('` and `', $defaults).'`',   // "`health` and `info`"
        implode(',', $defaults),                 // "health,info"
    ];

    $claims = 0;
    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            if (! str_contains($paragraph, 'exposure.include') || preg_match('/404|unexposed/i', $paragraph) !== 1) {
                continue;
            }

            $claims++;
            $scoped = array_filter($scopings, static fn (string $scoping): bool => stripos($paragraph, $scoping) !== false);
            expect($scoped)->not->toBe([], sprintf(
                '%s says an endpoint is a 404 until named in the exposure list without exempting %s, which '
                .'ExposureModel::fromConfig() exposes with no configuration at all.',
                $page,
                implode(' and ', $defaults),
            ));
        }
    }

    expect($claims)->toBeGreaterThanOrEqual(4);
});

it('pins every translator enumeration to the exceptions PersistenceExceptionTranslator really builds', function () {
    // DERIVED: the private build() is the whole mapping, and DriverErrorTable's public string constants are
    // the whole set of kinds it is ever handed. A ninth kind, or a kind rewired to another type, lands here.
    $build = new ReflectionMethod(PersistenceExceptionTranslator::class, 'build');
    $cause = new RuntimeException('driver said no');

    $produced = [];
    foreach ((new ReflectionClass(DriverErrorTable::class))->getConstants(ReflectionClassConstant::IS_PUBLIC) as $kind) {
        if (! is_string($kind)) {
            continue; // DRIVER_CODES and the SQLSTATE tables, not a kind.
        }
        $translated = $build->invoke(null, $kind, $cause, null);
        if (! $translated instanceof Throwable) {
            throw new RuntimeException(sprintf('build() answered %s for kind %s.', get_debug_type($translated), $kind));
        }
        $produced[] = basename(str_replace('\\', '/', $translated::class));
    }

    // The default arm: a code neither table classifies.
    $generic = $build->invoke(null, null, $cause, null);
    if (! $generic instanceof Throwable) {
        throw new RuntimeException(sprintf('build() answered %s for an unclassified code.', get_debug_type($generic)));
    }
    $produced[] = basename(str_replace('\\', '/', $generic::class));
    $produced = array_values(array_unique($produced));

    // The rest of the kernel family. Whatever is NOT in $produced cannot be attributed to a driver code —
    // this is the set the shipped sentence got wrong, and it is read off the directory, not typed out.
    $family = [];
    foreach ((array) glob($root = dirname(__DIR__).'/packages/kernel/src/Exception/Infrastructure/*.php') as $path) {
        $family[] = basename((string) $path, '.php');
    }
    $neverTranslated = array_values(array_diff($family, $produced));
    expect($neverTranslated)->toContain('OptimisticLockingFailureException', 'EmptyResultDataAccessException');

    // Where the two most quotable of them DO come from, proven from the sources rather than from the prose.
    $parents = class_parents(OptimisticLockException::class);
    expect($parents === false ? [] : array_values($parents))->toContain(OptimisticLockingFailureException::class);
    $trait = (string) file_get_contents((string) (new ReflectionClass(HasOptimisticLock::class))->getFileName());
    expect($trait)->toContain('new OptimisticLockException(');

    $raisedIn = static function (string $method, string $exception): void {
        $reflected = new ReflectionMethod(EloquentRepository::class, $method);
        $lines = file((string) $reflected->getFileName());
        $body = implode('', array_slice($lines === false ? [] : $lines, $reflected->getStartLine() - 1, $reflected->getEndLine() - $reflected->getStartLine() + 1));
        expect($body)->toContain('new '.$exception.'(');
    };
    $raisedIn('getById', 'EmptyResultDataAccessException');
    $raisedIn('findOneByExample', 'IncorrectResultSizeDataAccessException');

    // A paragraph that names the translator and four or more of the types it builds has taken on the list.
    $enumerations = 0;
    foreach (fireflyProsePages() as $page => $paragraphs) {
        foreach ($paragraphs as $paragraph) {
            if (! str_contains($paragraph, 'PersistenceExceptionTranslator')) {
                continue;
            }

            $named = array_values(array_filter(
                $produced,
                static fn (string $type): bool => str_contains($paragraph, '`'.$type.'`'),
            ));
            if (count($named) < 4) {
                continue; // A passing mention ("a DuplicateKeyException instead of a QueryException") owes nothing.
            }

            $enumerations++;
            $missing = array_values(array_diff($produced, $named));
            expect($missing)->toBe([], sprintf(
                '%s enumerates what PersistenceExceptionTranslator produces but omits %s.',
                $page,
                implode(', ', $missing),
            ));

            // A type the translator never builds may still appear — but only beside the seam that really
            // raises it, never inside the enumeration as a product of a SQLSTATE.
            $companions = [
                'OptimisticLockingFailureException' => 'OptimisticLockException',
                'EmptyResultDataAccessException' => 'getById',
                'IncorrectResultSizeDataAccessException' => 'findOneByExample',
            ];
            foreach ($neverTranslated as $type) {
                if (! str_contains($paragraph, '`'.$type.'`')) {
                    continue;
                }
                expect(str_contains($paragraph, $companions[$type] ?? $type))->toBeTrue(sprintf(
                    '%s lists %s among the translator\'s output; build() never returns it.',
                    $page,
                    $type,
                ));
            }
        }
    }

    // docs/architecture.md and docs/modules/data.md.
    expect($enumerations)->toBe(2);

    // The architecture page additionally pins the gate and the seams, because the bullet reads as if every
    // database error in the process were translated. Both halves are checked against firefly/data itself.
    $architecture = (string) file_get_contents(dirname(__DIR__).'/docs/architecture.md');
    expect(file_get_contents(dirname(__DIR__).'/packages/data/src/DataSettings.php'))
        ->toContain("'firefly.data.exception-translation.enabled'");
    expect($architecture)->toContain('firefly.data.exception-translation.enabled');

    $seams = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__).'/packages/data/src', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php'
            && str_contains((string) file_get_contents($file->getPathname()), '->translate(')) {
            $seams[] = $file->getBasename('.php');
        }
    }
    sort($seams);
    expect($seams)->toBe(['EloquentRepository', 'TransactionTemplate']);
    foreach ($seams as $seam) {
        expect(str_contains($architecture, $seam))->toBeTrue(sprintf(
            'docs/architecture.md describes where exception translation applies without naming %s, which calls translate().',
            $seam,
        ));
    }
});

it('pins every prefersHtml() paragraph to the order and the media types ErrorPageRenderer really checks', function () {
    // The fourth kind of wrong sentence, and the most expensive: one that states a fact about the FRAMEWORK
    // BELOW correctly and then draws the opposite conclusion from it. The shipped architecture bullet read
    // "Laravel tests the *first* acceptable type, so `Accept: application/json, text/html` names `text/html`
    // yet is plainly a machine asking" — the premise true, the example its exact inverse. Under that first-
    // type rule the header's first type is `application/json`, so `wantsJson()` is TRUE, so prefersHtml()
    // has already answered false two checks before `json-paths` is consulted. The clause the paragraph
    // existed to defend was being defended by the one header it does nothing for, on the security path that
    // decides whether an anonymous API call gets a 401 problem document or a `302 /login`.
    //
    // DERIVED, so the guard cannot repeat the mistake it catches: the ORDER of the three checks is read off
    // the method body, the accepted media types are read off its return expression, and the verdicts are
    // taken from the real method over real requests. Nothing below types out an answer the source could
    // contradict.
    $reflected = new ReflectionMethod(ErrorPageRenderer::class, 'prefersHtml');
    $lines = file((string) $reflected->getFileName());
    $body = implode('', array_slice($lines === false ? [] : $lines, $reflected->getStartLine() - 1, $reflected->getEndLine() - $reflected->getStartLine() + 1));

    $at = static function (string $needle) use ($body): int {
        $offset = strpos($body, $needle);
        if ($offset === false) {
            throw new RuntimeException(sprintf('ErrorPageRenderer::prefersHtml() no longer contains %s.', $needle));
        }

        return $offset;
    };
    expect($at('wantsJson()'))->toBeLessThan($at('isJsonPath('), 'prefersHtml() now consults json-paths before wantsJson()')
        ->and($at('isJsonPath('))->toBeLessThan($at("headers->get('Accept'"), 'prefersHtml() now reads Accept before json-paths');

    preg_match_all("/str_contains\(\\\$accept, '([^']+)'\)/", $body, $matches);
    $accepted = $matches[1];
    sort($accepted);
    expect($accepted)->toBe(['application/xhtml+xml', 'text/html'], 'the media types prefersHtml() names have changed');

    // The real method, over real requests. `api/*` is ErrorPageSettings' own default; the empty list is the
    // control that isolates what `json-paths` contributes and what it does not.
    $verdict = static function (string $accept, string $path, bool $jsonPaths): bool {
        $request = Request::create('/'.$path, 'GET');
        $request->headers->set('Accept', $accept);

        return (new ErrorPageRenderer(new ErrorPageSettings(jsonPaths: $jsonPaths ? ['api/*'] : [])))->prefersHtml($request);
    };

    // A header whose FIRST acceptable type is JSON is a wantsJson() call, answered before json-paths is
    // reached — turning the list off changes nothing, which is the proof that the clause did not decide it.
    expect($verdict('application/json, text/html', 'api/orders', true))->toBeFalse()
        ->and($verdict('application/json, text/html', 'api/orders', false))->toBeFalse()
        ->and($verdict('application/json, text/html', 'orders/999999', false))->toBeFalse();

    // The reverse ordering is the one that needs the clause: wantsJson() is false, `text/html` is named, and
    // only the path says this URL is a machine surface.
    expect($verdict('text/html, application/json', 'api/orders', false))->toBeTrue()
        ->and($verdict('text/html, application/json', 'api/orders', true))->toBeFalse();

    // Every Accept header a prefersHtml() paragraph quotes has to be one the clause it illustrates can still
    // reach. A header wantsJson() already rejects cannot demonstrate anything about json-paths.
    $paragraphs = 0;
    foreach (fireflyProsePages() as $page => $pageParagraphs) {
        foreach ($pageParagraphs as $paragraph) {
            if (! str_contains($paragraph, 'prefersHtml')) {
                continue;
            }

            $paragraphs++;

            foreach ($accepted as $type) {
                expect(str_contains($paragraph, $type))->toBeTrue(sprintf(
                    '%s describes the prefersHtml() browser test without naming %s, which the method accepts '
                    .'exactly as it accepts the other.',
                    $page,
                    $type,
                ));
            }

            if (! str_contains($paragraph, 'json-paths')) {
                continue;
            }

            preg_match_all('/`Accept:\s*([^`]+)`/', $paragraph, $headers);
            foreach ($headers[1] as $header) {
                $probe = Request::create('/api/orders', 'GET');
                $probe->headers->set('Accept', $header);
                expect($probe->wantsJson())->toBeFalse(sprintf(
                    '%s offers `Accept: %s` as what json-paths catches, but its first acceptable type is %s, '
                    .'so wantsJson() is true and prefersHtml() answers false two checks earlier — json-paths '
                    .'is never consulted. Use a header whose first type is text/html.',
                    $page,
                    $header,
                    $probe->getAcceptableContentTypes()[0] ?? 'nothing',
                ));
            }
        }
    }

    // docs/architecture.md's entry-point bullet, and docs/modules/security.md's prose and its settings table.
    expect($paragraphs)->toBe(3);
});
