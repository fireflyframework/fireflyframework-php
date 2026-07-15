<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BadListenerFixtures;

use Firefly\Context\Event\AsEventListener;

/**
 * Deliberately isolated in its OWN directory, OUTSIDE tests/Fixtures/ (RecursiveDirectoryIterator
 * walks subdirectories too, so nesting this under Fixtures/ would make the shared "scan the whole
 * Fixtures dir" tests throw as well) — scanned only by the dedicated "throws" test in
 * ContextScannerTest.
 *
 * #[AsEventListener] with no explicit event, whose first parameter is a BUILTIN type: nothing to
 * infer from, so ContextScanner must throw at SCAN time (never leave this to be discovered at
 * boot).
 */
final class UninferableListenerWidget
{
    #[AsEventListener]
    public function onSomething(string $notAnEvent): void {}
}
