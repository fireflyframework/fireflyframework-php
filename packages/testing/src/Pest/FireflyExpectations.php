<?php

declare(strict_types=1);

namespace Firefly\Testing\Pest;

use Firefly\Testing\Double\RecordingEventPublisher;
use RuntimeException;

/**
 * Installs Firefly's first-party Pest expectations. Called ONCE from the root tests/Pest.php — the only
 * Pest bootstrap file this monorepo loads, and (probed) the seam that reaches every package's tests suite.
 */
final class FireflyExpectations
{
    public static function register(): void
    {
        expect()->extend('toHavePublished', function (string $eventType, array $payloadContains = []) {
            // Pest rebinds $this to the Pest\Expectation under test deep inside its own internals — AFTER
            // this closure is handed to Extendable::extend() — so PHPStan (analysing the closure body in
            // isolation, with no visibility into that later rebind) cannot know $this's type here; the
            // enclosing method is static, so lexically there is no $this to infer from either. This is a
            // static-analysis gap in Pest's custom-expectation API, not a real defect (proven at runtime by
            // RecordingEventPublisherTest). We guard the resulting value with instanceof-narrow-or-throw
            // (this repo's established pattern; see EloquentRepository::applyPredicate's LIKE-ignore and
            // AfterCommitDispatchTest's asItemAdded/asNoteAdded) rather than an `@var`/cast override.
            // NOTE: Pest also rebinds the closure's *scope* to Expectation::class (Expectation::
            // getExpectationClosure() calls `bindTo($this, Expectation::class)`), so `self::` here would
            // resolve against Pest\Expectation, not this class — a bare `self::` call silently gets
            // intercepted by Expectation::__call() and forwarded onto $this->value instead of reaching this
            // class's helper. The literal class name below is required, not stylistic.
            // @phpstan-ignore variable.undefined, property.nonObject
            $publisher = FireflyExpectations::asRecordingEventPublisher($this->value);

            $matches = array_values(array_filter(
                $publisher->published,
                static function (array $e) use ($eventType, $payloadContains): bool {
                    if ($e['eventType'] !== $eventType) {
                        return false;
                    }
                    foreach ($payloadContains as $key => $value) {
                        if (! array_key_exists($key, $e['payload']) || $e['payload'][$key] !== $value) {
                            return false;
                        }
                    }

                    return true;
                },
            ));

            expect($matches)->not->toBeEmpty(
                "Expected an event of type [{$eventType}] with the given payload to have been published.",
            );

            // @phpstan-ignore variable.undefined
            return $this;
        });
    }

    /**
     * Narrow the Expectation's mixed `->value` to RecordingEventPublisher — instanceof-narrow-or-throw,
     * no `@var` override, no cast. Throwing here (rather than silently coercing) surfaces a clear failure
     * if `toHavePublished()` is ever called on a value that isn't a RecordingEventPublisher.
     *
     * PUBLIC (not private): the extend() closure above is invoked with its *scope* rebound to
     * Expectation::class by Pest internals, so a `private` method here would raise "Call to private
     * method ... from scope Pest\Expectation" — visibility is checked against the rebound scope, not the
     * literal class name used to call it.
     */
    public static function asRecordingEventPublisher(mixed $value): RecordingEventPublisher
    {
        if (! $value instanceof RecordingEventPublisher) {
            throw new RuntimeException('toHavePublished() expects the value under test to be a RecordingEventPublisher.');
        }

        return $value;
    }
}
