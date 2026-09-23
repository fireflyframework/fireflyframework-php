<?php

declare(strict_types=1);

namespace Firefly\Tests\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * `mkdocs.yml`, parsed — so that every assertion about the site is a question about a *key* and not about the
 * bytes of the file.
 *
 * That distinction is the whole reason this helper exists. A whole-file `str_contains($mkdocs, 'assets/…')`
 * is satisfied by any line that happens to spell the path, and this file's own voice is comment-heavy: the
 * comment above the `palette:` block already spells `docs/assets/stylesheets/larafly.css` while explaining
 * where the custom colours live, which is enough to keep a substring guard green on a site whose `extra_css`
 * key has been deleted outright — a guard failing open in exactly the scenario it exists to catch. Parsing
 * asks the question that was meant: does `theme.logo` / `theme.favicon` / `extra_css` still point at the file,
 * and does `nav:` still put the landing page where the theme will look for it.
 *
 * `!!python/name:` is mkdocs-material's own idiom for handing a Python callable to a Markdown extension (the
 * emoji extension is the usual one), and Symfony's parser rejects it as an unsupported built-in tag — even
 * with `PARSE_CUSTOM_TAGS`, which only covers single-`!` application tags. Nothing here ever reads such a
 * value, so those tags are quoted into ordinary strings before parsing rather than allowed to turn the tests
 * that use this red the day somebody turns that extension on.
 */
final class MkdocsConfig
{
    /**
     * The whole parsed document.
     *
     * @return array<mixed>
     */
    public static function load(): array
    {
        $raw = (string) file_get_contents(self::root().'/mkdocs.yml');

        return self::asArray(Yaml::parse((string) preg_replace('#!!python/\S+#', "'$0'", $raw)), 'the document root');
    }

    /**
     * The repository root, which is also the directory `mkdocs.yml` and `docs/` live in.
     */
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Narrow one `Yaml::parse()` `mixed` — the root, or an offset of it — to an array, naming what was
     * expected when it is not one. A missing `theme:` block and a `theme:` block that is a scalar are the
     * same failure to every assertion that reads it, and both deserve to say so rather than to trip a
     * PHPStan-shaped type error.
     *
     * @return array<mixed>
     */
    public static function asArray(mixed $value, string $what): array
    {
        if (! is_array($value)) {
            throw new RuntimeException("mkdocs.yml: {$what} is missing or is not a YAML collection.");
        }

        return $value;
    }

    /**
     * The `nav:` list — MkDocs builds the whole navigation from this and nothing else, so a document missing
     * from it is published and never linked.
     *
     * @return array<mixed>
     */
    public static function navigation(): array
    {
        return self::asArray(self::load()['nav'] ?? null, 'nav');
    }

    /**
     * Where each top-level tab lands: the entry's title against the document Material will link it to.
     *
     * @return array<string, string>
     */
    public static function tabTargets(): array
    {
        $targets = [];

        foreach (self::navigation() as $position => $entry) {
            $targets[self::entryTitle($entry, is_int($position) ? $position : 0)] = self::tabTarget($entry);
        }

        return $targets;
    }

    /**
     * The document a navigation entry's tab links to.
     *
     * `partials/tabs-item.html` is a few lines of Jinja and they are the entire contract: a SECTION's tab
     * links to `children | first`, descending while that first child is itself a section; a top-level PAGE
     * links to itself. Nothing there consults `navigation.indexes`, and nothing there looks for an index
     * file — which is why the first entry under a section, and only the first, decides where its tab opens.
     */
    public static function tabTarget(mixed $entry): string
    {
        $value = self::entryValue($entry);

        if (is_string($value)) {
            return $value;
        }

        $children = self::asArray($value, 'a navigation section');
        $first = array_key_first($children);

        if ($first === null) {
            throw new RuntimeException('mkdocs.yml: a navigation section is empty, so its tab would link nowhere.');
        }

        return self::tabTarget($children[$first]);
    }

    /**
     * The titles of the navigation sections that really contain an index page — a child document MkDocs
     * would answer `Page::is_index` for, which is `index.md` or `README.md` and nothing else.
     *
     * This is the precondition of Material's `navigation.indexes`, and the reason it is worth computing
     * rather than assuming: the feature binds a page to its section HEADING, and it can only ever fire for a
     * page in this list. Listed with the list empty, it is a line of configuration that changes nothing.
     *
     * @return list<string>
     */
    public static function sectionsWithIndexPage(): array
    {
        return self::indexSectionsIn(self::navigation());
    }

    /**
     * @param  array<mixed>  $entries
     * @return list<string>
     */
    private static function indexSectionsIn(array $entries): array
    {
        $found = [];

        foreach ($entries as $position => $entry) {
            $value = self::entryValue($entry);

            if (is_string($value)) {
                continue;
            }

            $title = self::entryTitle($entry, is_int($position) ? $position : 0);
            $children = self::asArray($value, "the navigation section '{$title}'");

            foreach ($children as $child) {
                $document = self::entryValue($child);

                if (is_string($document) && in_array(basename($document), ['index.md', 'README.md'], true)) {
                    $found[] = $title;

                    break;
                }
            }

            foreach (self::indexSectionsIn($children) as $nested) {
                $found[] = $nested;
            }
        }

        return $found;
    }

    /**
     * A navigation entry is either a bare document (`- modules.md`) or the one-key map that titles one
     * (`- Overview: modules.md`, `- Modules: [...]`). This is the title such an entry shows.
     */
    public static function entryTitle(mixed $entry, int $position): string
    {
        if (is_string($entry)) {
            return $entry;
        }

        $pair = self::asArray($entry, "the navigation entry at position {$position}");
        $title = array_key_first($pair);

        return is_string($title) ? $title : "the navigation entry at position {$position}";
    }

    /**
     * What a navigation entry points at: a document path, or the list of children that makes it a section.
     */
    public static function entryValue(mixed $entry): mixed
    {
        if (is_string($entry)) {
            return $entry;
        }

        $pair = self::asArray($entry, 'a navigation entry');
        $title = array_key_first($pair);

        if ($title === null) {
            throw new RuntimeException('mkdocs.yml: a navigation entry is an empty map, so it names nothing.');
        }

        return $pair[$title];
    }

    /**
     * The `theme.features` list, as a list of strings.
     *
     * @return list<string>
     */
    public static function features(): array
    {
        $theme = self::asArray(self::load()['theme'] ?? null, 'theme');

        return array_values(array_filter(
            self::asArray($theme['features'] ?? null, 'theme.features'),
            is_string(...),
        ));
    }
}
