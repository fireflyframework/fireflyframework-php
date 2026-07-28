"""Extract fenced PHP code listings from the manuscript and verify they lint.

Unlike PyFly's Python-native `ast.parse`, LaraFly's listings are real PHP, so
this shells out to the PHP CLI's own linter (`php -l`) via a temp file. Used
standalone:

    book/.venv/bin/python book/build/verify_code.py book/src
    book/.venv/bin/python book/build/verify_code.py book/src-es
"""
from __future__ import annotations
import os
import re
import subprocess
import sys
import tempfile
from dataclasses import dataclass
from pathlib import Path

_FENCE_OPEN = re.compile(r'^```\s*(?P<lang>[\w.+-]*)\s*$')
_FENCE_CLOSE = re.compile(r'^```\s*$')


@dataclass
class Listing:
    lang: str
    code: str
    source: Path
    line: int


def extract_php_listings(md_file: Path) -> list[Listing]:
    """Scan a Markdown file for fenced code blocks (```lang ... ```) and return
    only those tagged ```php``. Other fenced languages (bash, yaml, ...) are
    skipped — they are not PHP and are not linted here."""
    out: list[Listing] = []
    lines = Path(md_file).read_text(encoding="utf-8").splitlines()
    i = 0
    while i < len(lines):
        m = _FENCE_OPEN.match(lines[i])
        if not m:
            i += 1
            continue
        lang = m["lang"].strip().lower()
        start = i + 1
        body: list[str] = []
        i += 1
        while i < len(lines) and not _FENCE_CLOSE.match(lines[i]):
            body.append(lines[i])
            i += 1
        i += 1  # skip the closing fence
        if lang == "php":
            out.append(Listing(lang=lang, code="\n".join(body), source=Path(md_file), line=start))
    return out


def lint_php(code: str) -> tuple[bool, str | None]:
    """Run the code through `php -l` (syntax check only, no execution).

    Fragments that omit the opening `<?php` tag are given one automatically so
    that inline snippets (e.g. a lone expression or class body) still lint.
    """
    src = code if re.match(r'^\s*<\?php', code) else f"<?php\n{code}"
    fd, path = tempfile.mkstemp(suffix=".php")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            f.write(src)
        proc = subprocess.run(["php", "-l", path], capture_output=True, text=True)
        ok = proc.returncode == 0
        err = None if ok else (proc.stdout + proc.stderr).strip()
        return ok, err
    finally:
        os.unlink(path)


def main(root: str) -> int:
    files = sorted(Path(root).rglob("*.md"))
    failures = 0
    checked = 0
    for f in files:
        for lst in extract_php_listings(f):
            checked += 1
            ok, err = lint_php(lst.code)
            if not ok:
                failures += 1
                print(f"FAIL {f}:{lst.line} [php] {err}")
    print(f"verify_code: {failures} failing listing(s) across {len(files)} file(s), "
          f"{checked} php listing(s) checked")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1] if len(sys.argv) > 1 else "book/src"))
