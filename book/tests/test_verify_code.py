# book/tests/test_verify_code.py
from build.verify_code import _marker_above, extract_listings, extract_php_listings, lint_php


def test_extracts_only_php_listings(tmp_path):
    md = tmp_path / "c.md"
    md.write_text("```php\n<?php $x = 1;\n```\n\n```bash\nls\n```\n")
    listings = extract_php_listings(md)
    assert [l.lang for l in listings] == ["php"]


def test_rejects_a_syntax_error():
    ok, _ = lint_php("<?php function (:")
    assert ok is False


def test_accepts_valid_php_fragment_without_opening_tag():
    ok, err = lint_php("$x = 1 + 1;")
    assert ok is True and err is None


def test_reads_the_source_marker_above_a_fence(tmp_path):
    md = tmp_path / "c.md"
    md.write_text("<!-- source: packages/kernel/src/Version.php -->\n\n```php\n$x = 1;\n```\n")
    listing = extract_listings(md)[0]
    assert listing.origin == "packages/kernel/src/Version.php"
    assert listing.illustrative is None


def test_reads_the_illustrative_marker_and_its_reason(tmp_path):
    md = tmp_path / "c.md"
    md.write_text("<!-- illustrative: the reader's own controller -->\n```php\n$x = 1;\n```\n")
    listing = extract_listings(md)[0]
    assert listing.illustrative == "the reader's own controller"
    assert listing.origin is None


def test_does_not_inherit_a_marker_from_an_earlier_block():
    lines = ["<!-- source: a.php -->", "```php", "$x = 1;", "```", "", "Some prose.", "", "```php"]
    assert _marker_above(lines, 6) == (None, None)
