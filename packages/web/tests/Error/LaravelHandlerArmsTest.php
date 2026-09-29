<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * THE TRIPWIRE UNDER `ErrorPageRenderer::describes()`, and the only thing in this repository that would
 * notice a Laravel release resolving a FOURTH throwable for itself.
 *
 * WHY THE PREDICATE'S OWN TEST IS NOT THIS. `ErrorPageTest` asserts describes() against
 * HttpResponseException, AuthenticationException and ValidationException, and it constructs all three
 * itself: it pins what our method does, and it would stay green for ever if Laravel grew a fourth arm,
 * because nothing in it ever looks at Laravel's handler. For one release the describes() docblock claimed
 * that test as protection against exactly that — which is worse than having no tripwire, because a
 * maintainer doing a Laravel upgrade reads the promise and skips re-checking the list, and the new arm
 * becomes a 500/`INTERNAL_ERROR` problem document in place of whatever Laravel would have resolved. This
 * file is that claim made true.
 *
 * DERIVED, NOT TYPED OUT. `Handler::render()` is read out of the INSTALLED Laravel through reflection —
 * the class's own file, so it does not matter where the vendor tree lives — and the classes its
 * `match (true)` resolves are extracted from that source. Nothing below asserts a behaviour of Laravel's
 * that could drift silently; it asserts the shape of the decision our renderable has to stand aside from,
 * which is precisely the thing a `composer update` can change under us.
 *
 * WHAT A FAILURE HERE MEANS. Not that this test is wrong: that `packages/web/src/Error/ErrorPageRenderer.php`
 * has a list to re-check. Read the arms this test found, decide whether the new one is a throwable Laravel
 * resolves ITSELF after `renderViaCallbacks()` has run (it is, if it is in that match), add it to
 * describes(), add the pipeline case to `CapstoneWebIntegrationTest`, and update this expectation.
 */
it('finds exactly the three throwables describes() stands aside for in Laravel\'s own Handler::render()', function () {
    $method = new ReflectionMethod(Handler::class, 'render');
    $file = $method->getFileName();

    expect($file)->toBeString();

    $source = is_string($file) ? (string) file_get_contents($file) : '';
    $lines = explode("\n", $source);
    $body = implode("\n", array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

    // Everything from the `match (true) {` to the end of the method: the arms, and anything a future
    // release adds after them. Taken from that offset rather than from the top of the method because
    // render() tests `$e instanceof Responsable` several lines ABOVE the match, and that one is resolved
    // BEFORE renderViaCallbacks() runs — a Responsable never reaches our renderable at all, so it is not
    // one of the classes describes() has to name.
    $match = strstr($body, 'match (true) {');

    expect($match)->toBeString('Handler::render() no longer resolves anything with a `match (true)`, so the shape ErrorPageRenderer::describes() was written against has changed. Re-read the method.');

    preg_match_all('/\$e instanceof ([A-Za-z_\\\\]+)\s*=>/', is_string($match) ? $match : '', $found);

    $arms = $found[1];
    sort($arms);

    // Sorted, so re-ordering the arms — which changes nothing about WHICH throwables Laravel claims — is
    // not a red build. Adding or removing one is.
    expect($arms)->toBe(['AuthenticationException', 'HttpResponseException', 'ValidationException']);

    // And the short names in that match are the classes describes() actually names: the arms are written
    // unqualified, so without this the test would pass on an import swapped to a same-named class in
    // another namespace.
    foreach ([AuthenticationException::class, HttpResponseException::class, ValidationException::class] as $class) {
        expect($source)->toContain('use '.$class.';');
    }
});
