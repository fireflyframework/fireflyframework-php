<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BadListenerNoParamsFixtures;

use Firefly\Context\Event\AsEventListener;

/**
 * Deliberately isolated in its OWN directory, OUTSIDE tests/Fixtures/ AND OUTSIDE
 * BadListenerFixtures/ (RecursiveDirectoryIterator walks subdirectories too, so nesting this under
 * either would make that directory's own dedicated "throws" test observe THIS class's throw
 * instead of — or in addition to — its own) — scanned only by the dedicated "no parameters at all"
 * test in ContextScannerTest.
 *
 * #[AsEventListener] with no explicit event, whose method has NO PARAMETERS AT ALL — nothing to
 * infer from, and no first parameter to even inspect. ContextScanner must throw
 * ConfigurationException at SCAN time, naming this class and method, never a bare PHP Error from an
 * unguarded array access.
 */
final class NoParamsListenerWidget
{
    #[AsEventListener]
    public function onSomething(): void {}
}
