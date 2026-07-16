<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\DedupeKeyFixtures;

/**
 * M4 review #8, Minor (b): a `Scope::Transient` `#[Bean]` factory bound to this ONE abstract, whose
 * factory returns a DIFFERENT concrete class across resolutions (VaryImplA the first time, VaryImplB
 * every time after) — the docblock's own disclosed edge case (`RegisterBeanPostProcessorsPass`
 * :240-243, pre-fix), which review #8 measured as backwards under the old `$concreteClass` key.
 */
interface VaryPort {}
