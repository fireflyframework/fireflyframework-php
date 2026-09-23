# book/tests/test_verify_code.py
from build.verify_code import _marker_above, extract_listings, extract_php_listings, lint_php, main


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


def test_a_source_listing_is_not_linted_but_an_unmarked_one_is(tmp_path, capsys):
    """A `source:` excerpt is a fragment of a file the repository already lints.

    A verbatim excerpt of one method, or of a docblock, cannot parse on its own — and
    mutilating it until it does is exactly the untrue listing the provenance rule exists
    to stop. So the lint applies to everything EXCEPT a `source:` listing, which the PHP
    guard has already compared line for line against real, already-linted code. An
    `illustrative:` listing is the reader's own class and nothing else backs it, so it is
    still linted.
    """
    (tmp_path / "excerpt.md").write_text(
        "<!-- source: packages/kernel/src/Version.php -->\n```php\npublic function only(): string\n{\n```\n"
    )
    assert main(str(tmp_path)) == 0

    (tmp_path / "excerpt.md").write_text(
        "<!-- illustrative: the reader's own service, which no file here contains -->\n"
        "```php\npublic function only(): string\n{\n```\n"
    )
    assert main(str(tmp_path)) == 1
    assert "Parse error" in capsys.readouterr().out


def test_require_provenance_still_refuses_a_listing_with_no_marker(tmp_path, capsys):
    (tmp_path / "bare.md").write_text("```php\n$x = 1;\n```\n")
    assert main(str(tmp_path), require_provenance=True) == 1
    assert "no provenance" in capsys.readouterr().out
