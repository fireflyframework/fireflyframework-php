<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Marks a public method as a scheduled task (pyfly/Spring @Scheduled parity). Exactly one trigger is required:
 * `cron` (a Laravel/crontab expression, applied verbatim), `fixedRate`, or `fixedDelay` (duration strings parsed
 * by Duration::parse at wiring time and mapped to the nearest native Laravel frequency). `lock === true` shares a
 * lock named "Class::method"; a string is an explicit shared lock name; null/false runs unlocked. The scanner
 * (the package's sole reflection site) reads these into pure-array descriptors; nothing here parses durations.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Scheduled
{
    public function __construct(
        public ?string $cron = null,
        public ?string $fixedRate = null,
        public ?string $fixedDelay = null,
        public ?string $initialDelay = null,
        public ?string $zone = null,
        public string|bool|null $lock = null,
        public ?string $lockTtl = null,
    ) {
        $triggers = array_filter(
            [$cron, $fixedRate, $fixedDelay],
            static fn (?string $trigger): bool => $trigger !== null,
        );

        if (count($triggers) !== 1) {
            throw new InvalidArgumentException(
                '#[Scheduled] requires exactly one of cron, fixedRate or fixedDelay to be set.',
            );
        }
    }
}
