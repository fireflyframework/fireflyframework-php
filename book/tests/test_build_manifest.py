"""Configured manuscripts must be complete before publishing an edition."""

import pytest

from build import build as book_build


@pytest.mark.parametrize("config,manuscript_dir", [("book.yaml", "src"), ("book.es.yaml", "src-es")])
@pytest.mark.parametrize("missing_file", ["front.md", "chapter.md"])
def test_build_rejects_missing_configured_manuscript_before_writing(
    tmp_path, monkeypatch, config, manuscript_dir, missing_file
):
    source = tmp_path / "book"
    manuscript = source / manuscript_dir
    manuscript.mkdir(parents=True)
    for name in ("front.md", "chapter.md"):
        if name != missing_file:
            (manuscript / name).write_text("# Present\n")
    (source / config).write_text(
        "title: Book\nauthor: Author\nlanguage: en\nidentifier: urn:uuid:book\n"
        f"manuscript_dir: {manuscript_dir}\ncover_png: missing.png\n"
        "front:\n  - {id: front, file: front.md, title: Front}\n"
        "parts:\n  - title: Part I\n    chapters:\n"
        "      - {id: chapter, file: chapter.md, num: 1, title: Hello}\n"
    )
    out = tmp_path / "dist"
    monkeypatch.setattr(book_build, "BOOK", source)
    monkeypatch.setattr(book_build, "DIST", out)
    monkeypatch.setattr(book_build, "render_pdf", lambda *args, **kwargs: kwargs["out"].write_bytes(b"%PDF-1.7\n%%EOF"))

    with pytest.raises(FileNotFoundError, match=missing_file):
        book_build.main(["--config", config])
    assert not out.exists()
