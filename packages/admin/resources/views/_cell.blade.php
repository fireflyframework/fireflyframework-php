{{--
    One table cell, typed.

    VALUES ARRIVE RAW. An Eloquent-backed row holds whatever the driver returned, so a bool column can be
    int 1 and a json column a string. The COLUMN TYPE is the rendering hint and the value's PHP type is
    never consulted — that distinction is what stops a listing rendering `0` as an empty cell on one driver
    and `false` on another.

    Each type gets the treatment that makes a table readable rather than merely correct: numbers are
    right-aligned with tabular figures so digits line up down the column, booleans are a chip because a
    two-state value is faster to scan as a shape than as the word "false", null is a dim em-dash so an
    absent value is visibly different from an empty string, and json is monospaced and clipped with its full
    text on hover.
--}}
@php
    use Firefly\Admin\Data\DataColumn;

    $type = $column->type;
    $isNull = $value === null;
    $text = match (true) {
        $isNull => '—',
        $type === DataColumn::TYPE_BOOL => ((int) $value) === 1 ? 'true' : 'false',
        $type === DataColumn::TYPE_JSON => is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES),
        is_scalar($value) => (string) $value,
        default => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
    };
@endphp
<td class="cell t-{{ $type }} @if ($isNull) nil @endif @if ($column->sensitive) secret @endif" title="{{ $text }}">
    @if ($isNull)
        <span class="nul">—</span>
    @elseif ($type === DataColumn::TYPE_BOOL)
        <span class="bool {{ ((int) $value) === 1 ? 'yes' : 'no' }}">{{ $text }}</span>
    @elseif ($column->identifier)
        <span class="idv">{{ $text }}</span>
    @else
        <span class="v">{{ $text }}</span>
    @endif
</td>
