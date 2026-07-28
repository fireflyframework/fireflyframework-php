# book/tests/test_verify_code.py
from build.verify_code import extract_php_listings, lint_php


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
