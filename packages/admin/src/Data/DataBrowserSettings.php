<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

use Firefly\Config\Config;

/**
 * The gates on the database browser. Read this before changing a default.
 *
 * WHY `firefly.admin.data.enabled` DEFAULTS TO FALSE WHEN THE DASHBOARD ITSELF DEFAULTS TO app.debug.
 * AdminSettings makes the dashboard follow `app.debug`, and the argument there is sound for what the
 * dashboard shows: beans, conditions, mappings and resolved configuration are facts about the APPLICATION,
 * and an application already serving stack traces has already published facts of that kind. This page shows
 * facts about the application's USERS. A debug flag left on in a staging environment that shares a database
 * with production, a `.env` copied to a box that was supposed to be internal, a developer laptop tunnelled
 * for a demo — every one of those is a routine mistake that costs nothing today and becomes a customer-record
 * disclosure the moment a browser is wired to app.debug. So the gate is separate, it is explicit, and it is
 * off: `app.debug` cannot switch it on, and neither can `firefly.admin.enabled`. Both must already be true
 * AND this key must be set.
 *
 * WHY WRITES HAVE A SECOND, SEPARATE GATE. Reading the wrong row is a disclosure; deleting it is data loss
 * with no undo, from a form, over a session that may be nothing more than "debug was on". Turning on the
 * browser is a decision about visibility and turning on writes is a decision about custody, and collapsing
 * the two into one key means the operator who wanted to look at a table also armed the delete button. So
 * `firefly.admin.data.writable` is its own key, also false, and it is INEFFECTIVE on its own — writes require
 * both, and DataBrowser refuses with a stated reason rather than silently doing nothing.
 *
 * PAGE SIZE IS CAPPED, NOT MERELY DEFAULTED. `?perPage=1000000` on a resource whose repository cannot page is
 * a request to materialise the table into PHP memory, and the failure mode is an OOM that takes the whole
 * worker with it. The cap is applied to whatever the caller asks for, so the query layer never sees a size it
 * did not agree to.
 */
final readonly class DataBrowserSettings
{
    /** The largest `max-page-size` an application may configure — past this, one request can OOM a worker. */
    public const int PAGE_SIZE_CEILING = 1000;

    /**
     * @param  list<string>  $excluded  resource slugs hidden from the menu and refused by every operation
     */
    public function __construct(
        public bool $enabled = false,
        public bool $writable = false,
        public int $pageSize = 25,
        public int $maxPageSize = 200,
        public array $excluded = [],
    ) {}

    public static function fromConfig(Config $config): self
    {
        $max = min(self::PAGE_SIZE_CEILING, max(1, $config->int('firefly.admin.data.max-page-size', 200)));

        return new self(
            enabled: $config->bool('firefly.admin.data.enabled', false),
            writable: $config->bool('firefly.admin.data.writable', false),
            pageSize: min($max, max(1, $config->int('firefly.admin.data.page-size', 25))),
            maxPageSize: $max,
            excluded: self::csv($config->string('firefly.admin.data.exclude', '')),
        );
    }

    /**
     * Writing requires BOTH gates. Kept as a predicate rather than a precomputed flag so the two config keys
     * stay separately readable on the settings object — a page that shows "browser: on, writes: off" is
     * telling the operator something a single collapsed boolean could not.
     */
    public function canWrite(): bool
    {
        return $this->enabled && $this->writable;
    }

    /**
     * Whether a resource may be reached at all.
     *
     * `firefly.admin.data.exclude` is a hard refusal, not a menu preference — the resource is hidden AND
     * every operation on it is refused, the same contract AdminSettings::allows() gives page slugs. An
     * application that hides `user` because the table holds PII has achieved nothing if the row URL still
     * answers.
     */
    public function allows(string $slug): bool
    {
        return ! in_array($slug, $this->excluded, true);
    }

    /** Clamp a caller-supplied page size into [1, maxPageSize]; null means "use the configured default". */
    public function clampPageSize(?int $requested): int
    {
        if ($requested === null) {
            return $this->pageSize;
        }

        return min($this->maxPageSize, max(1, $requested));
    }

    /** @return list<string> */
    private static function csv(string $value): array
    {
        return array_values(array_filter(
            array_map(static fn (string $part): string => strtolower(trim($part)), explode(',', $value)),
            static fn (string $part): bool => $part !== '',
        ));
    }
}
