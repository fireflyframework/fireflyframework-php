<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\DedupeKeyFixtures;

/**
 * M4 review #8, Minor (a): one of TWO distinct abstracts `Repo` is bound under, each by its OWN
 * `#[Bean]` factory — the shape that exposed the dedupe guard's wrong key (`$concreteClass`, which
 * `Repo::class` is for BOTH abstracts, so registering the first abstract's listener silently
 * suppressed the second).
 */
interface ReadPort {}
