<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures;

use Firefly\Validation\Constraint\BeanValidator;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\Constraint\ConstraintManifestCompiler;
use Firefly\Validation\IlluminateValidator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

/**
 * Shared wiring for the constraint suites: compile a DTO's constraints through the REAL compiler, round-trip
 * them through the manifest envelope, and hand back a BeanValidator over a real Illuminate factory.
 *
 * Deliberately a class of static methods rather than a Pest global helper (the shape BeanValidatorTest uses):
 * Pest includes test files lazily as it runs them, so a global function declared in one test file is only
 * defined once THAT file has been reached. A PSR-4 class is autoloaded on first use, so any test file can
 * reach it regardless of execution order or of which subset of the suite is being run.
 */
final class ValidatorHarness
{
    /**
     * @param  class-string  ...$classes
     */
    public static function beanValidator(string ...$classes): BeanValidator
    {
        $rows = (new ConstraintManifestCompiler)->toArray(array_values($classes));

        return new BeanValidator(
            new IlluminateValidator(new IlluminateFactory(new Translator(new ArrayLoader, 'en'))),
            ConstraintManifest::fromArray($rows),
        );
    }
}
