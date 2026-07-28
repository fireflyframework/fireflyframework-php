# book/tests/test_md.py
from build.md import render_markdown  # noqa: E402


def test_listing_renders_filetab_caption_and_highlight(tmp_path):
    src = (
        "::: listing app/Domain/Money.php | Listing 0.1 — hello\n"
        "<?php\n"
        "final class Money { public int $minorUnits; }\n"
        ":::\n"
    )
    html = render_markdown(src, tmp_path)
    assert 'class="filetab">app/Domain/Money.php<' in html
    assert "Listing 0.1" in html
    assert 'class="listing"' in html
    assert "<span" in html  # pygments emitted token spans


def test_fenced_php_code_is_highlighted(tmp_path):
    html = render_markdown("```php\n<?php $x = 1;\n```\n", tmp_path)
    assert "codehilite" in html or 'class="code' in html


def test_figure_inlines_svg(tmp_path):
    (tmp_path / "f.svg").write_text('<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>')
    html = render_markdown("::: figure f.svg | Figure 0.1 — demo\n", tmp_path)
    assert "<figure" in html and "<svg" in html and "Figure 0.1" in html


def test_laravel_callout(tmp_path):
    html = render_markdown('!!! laravel "Laravel parity"\n    Same as Laravel.\n', tmp_path)
    assert "admonition laravel" in html and "Laravel parity" in html


def test_note_tip_warning_callouts(tmp_path):
    html = render_markdown(
        '!!! note "Note"\n    A note.\n\n'
        '!!! tip "Tip"\n    A tip.\n\n'
        '!!! warning "Warning"\n    A warning.\n',
        tmp_path,
    )
    assert "admonition note" in html
    assert "admonition tip" in html
    assert "admonition warning" in html
