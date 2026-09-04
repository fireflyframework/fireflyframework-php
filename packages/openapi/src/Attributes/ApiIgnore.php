<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Attributes;

use Attribute;

/**
 * Leaves a controller — or one action on it — out of the generated document entirely.
 *
 * There was already a way to hide routes (`firefly.openapi.exclude`, a list of path prefixes) and it is the
 * wrong tool for this job twice over: it lives in config rather than next to the code, so nothing reminds
 * anyone to update it when a route moves, and it is keyed by PATH, so hiding `/internal/reindex` also hides
 * every future route that happens to start with those characters. #[ApiIgnore] is keyed by the thing that
 * actually decides — the class or the method — and travels with it through every rename and remount.
 *
 * This is NOT a security control. The route still exists and still answers; only its description is withheld.
 * Anything that must not be reachable belongs behind #[PreAuthorize] or is not a route at all. What it IS for
 * is the operation that is real but not part of the published contract: a health probe, an internal
 * back-office action, an endpoint that exists for one deploy while a client migrates.
 *
 * On a CLASS it removes every route the class declares; on a METHOD it removes exactly that route. There is
 * no "un-ignore" on a method inside an ignored class, because a whitelist inside a blacklist is a rule nobody
 * can read off the code at a glance.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class ApiIgnore {}
