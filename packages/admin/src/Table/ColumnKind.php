<?php

declare(strict_types=1);

namespace Firefly\Admin\Table;

/**
 * The closed vocabulary a dashboard column can belong to.
 *
 * The vocabulary is what replaces the six ad-hoc width mechanisms this sheet had grown — `.tight`, `.cls`,
 * `.mono wrap`, `.num`, a `td.text` nothing used, and three inline `style="width:…"` attributes. Each of
 * those answered one page's question; none of them could answer "how wide should this be" for a column the
 * author had not seen, which is why the Path column of a seven-route table collapsed to one glyph.
 *
 * THE MOST IMPORTANT DISTINCTION IN THIS ENUM IS `Path` VERSUS `Qualified`. `/api/v1/orgs/{o}/workspaces`
 * and `App\Http\Controllers\Api\V1\WorkspaceController` are the same length in the same font, and they must
 * elide in OPPOSITE directions. A path is discriminated by its HEAD — `/api/v1/orgs/…` already tells you
 * which family of routes you are looking at — so it clips at the end. A qualified name is discriminated by
 * its LEAF — `WorkspaceController` is the answer and `App\Http\Controllers\Api\V1` is where it lives — so
 * it is drawn on two lines with the leaf on top, and it is the PREFIX that may go. Parameterising the
 * separator (`\` for a class, `.` for a config key or a meter name) is what lets config properties, the
 * environment and the loggers reuse it rather than growing a fourth mechanism.
 *
 * RIGID versus FLEXIBLE is the other half. A rigid column's content has a known alphabet — six letters for
 * the longest HTTP verb, nineteen characters for an ISO timestamp — so it is sized from that and never
 * moves. A flexible column takes a share of whatever is left, in proportion to how much a reader needs.
 * `table-layout:fixed` splits leftover width EVENLY between columns with no declared width, which is
 * exactly how a one-word `Name` column ends up as wide as a fully-qualified class name; TableView computes
 * the shares instead.
 */
enum ColumnKind: string
{
    /** A chip: an HTTP verb, a status code, a boolean, a log level. Centred, never wrapped. */
    case Pill = 'pill';

    /** A figure. Right-aligned with tabular numerals so digits line up down the column. */
    case Number = 'num';

    /** An instant or an age. Monospaced, dim, never wrapped. */
    case Stamp = 'stamp';

    /** A bar drawn from another column's value. */
    case Meter = 'meter';

    /** Controls — a form, a link into a record. Shrinks to what it contains. */
    case Actions = 'actions';

    /** A short identifier: a route name, a log channel, a cache store, a config prefix. */
    case Token = 'token';

    /** A URL path or a file path: discriminated by its head, so it clips at the end. */
    case Path = 'path';

    /** A separator-qualified name: leaf on top, stem under it, and the stem is the half that may go. */
    case Qualified = 'qual';

    /** Prose. The only kind that WRAPS, and it wraps at word boundaries. */
    case Text = 'text';

    /** One clipped line with the whole value on the title: a json blob, a resolved config value. */
    case Line = 'line';

    public function cssClass(): string
    {
        return 't-'.$this->value;
    }

    /** Whether this kind is sized from its own alphabet rather than from what is left over. */
    public function isRigid(): bool
    {
        return match ($this) {
            self::Pill, self::Number, self::Stamp, self::Meter, self::Actions => true,
            self::Token, self::Path, self::Qualified, self::Text, self::Line => false,
        };
    }

    /** A picture of another column, and a set of controls, have nothing to order by. */
    public function isOrderable(): bool
    {
        return $this !== self::Meter && $this !== self::Actions;
    }
}
