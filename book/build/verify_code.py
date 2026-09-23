"""Extract fenced listings from the manuscript, verify they lint, and (optionally)
verify that every PHP listing declares where it came from.

The lint applies to every ```php listing EXCEPT one carrying a `<!-- source: -->` marker:
that listing is a verbatim fragment of a file the repository already lints, and a fragment
(one method, a docblock, an interface's signatures) does not parse alone.

Unlike PyFly's Python-native `ast.parse`, LaraFly's listings are real PHP, so
this shells out to the PHP CLI's own linter (`php -l`) via a temp file. Used
standalone:

    book/.venv/bin/python book/build/verify_code.py book/src
    book/.venv/bin/python book/build/verify_code.py book/src-es --require-provenance

`--require-provenance` is the Python half of tests/DocsCodeIsRealTest.php: it asserts
that every ```php listing carries a `<!-- source: <path> -->` or
`<!-- illustrative: <why> -->` comment immediately above its fence. The VERBATIM
comparison against the named file lives in the PHP guard, which already indexes the
repository; this side keeps the book's own toolchain from ever accepting a listing
with no provenance at all.
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
_SOURCE = re.compile(r'^<!--\s*source:\s*(?P<path>\S.*?)\s*-->$')
_ILLUSTRATIVE = re.compile(r'^<!--\s*illustrative:\s*(?P<why>\S.*?)\s*-->$')


@dataclass
class Listing:
    lang: str
    code: str
    source: Path
    line: int
    origin: str | None = None
    illustrative: str | None = None


def _marker_above(lines: list[str], index: int) -> tuple[str | None, str | None]:
    """The marker comment immediately above a fence, blank lines ignored. Anything
    else ends the search, so a marker is never inherited from an earlier block."""
    while index >= 0 and lines[index].strip() == "":
        index -= 1
    if index < 0:
        return None, None
    line = lines[index].strip()
    m = _SOURCE.match(line)
    if m:
        return m["path"], None
    m = _ILLUSTRATIVE.match(line)
    if m:
        return None, m["why"]
    return None, None


def extract_listings(md_file: Path) -> list[Listing]:
    """Every fenced block in the file, with the marker that stood above it."""
    out: list[Listing] = []
    lines = Path(md_file).read_text(encoding="utf-8").splitlines()
    i = 0
    while i < len(lines):
        m = _FENCE_OPEN.match(lines[i])
        if not m:
            i += 1
            continue
        lang = m["lang"].strip().lower()
        origin, illustrative = _marker_above(lines, i - 1)
        start = i + 1
        body: list[str] = []
        i += 1
        while i < len(lines) and not _FENCE_CLOSE.match(lines[i]):
            body.append(lines[i])
            i += 1
        i += 1  # skip the closing fence
        out.append(Listing(lang=lang, code="\n".join(body), source=Path(md_file),
                           line=start, origin=origin, illustrative=illustrative))
    return out


def extract_php_listings(md_file: Path) -> list[Listing]:
    """Only the ```php listings — what the linter runs over."""
    return [l for l in extract_listings(md_file) if l.lang == "php"]


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


def main(root: str, require_provenance: bool = False) -> int:
    files = sorted(Path(root).rglob("*.md"))
    failures = 0
    checked = 0
    unprovenanced = 0
    for f in files:
        for lst in extract_php_listings(f):
            checked += 1
            # A `source:` listing is a VERBATIM fragment of a file this repository already lints on
            # every build — one method lifted out of its class, a docblock, an interface's signatures.
            # Such a fragment cannot parse on its own, and the only way to make it parse is to add
            # lines the source file does not have, which is precisely the untrue listing the
            # provenance rule exists to stop. The PHP guard (tests/DocsCodeIsRealTest.php) draws the
            # same line for the same reason and compares the excerpt against the named file instead.
            # Everything else is still linted: an `illustrative:` listing is the reader's own class,
            # backed by no file, so a syntax error in it would ship to a reader unchallenged.
            if lst.origin is None:
                ok, err = lint_php(lst.code)
                if not ok:
                    failures += 1
                    print(f"FAIL {f}:{lst.line} [php] {err}")
            if require_provenance and lst.origin is None and lst.illustrative is None:
                unprovenanced += 1
                print(f"FAIL {f}:{lst.line} [php] no provenance: add "
                      f"<!-- source: <repo-relative-path> --> or <!-- illustrative: <why> --> above the fence")
    print(f"verify_code: {failures + unprovenanced} failing listing(s) across {len(files)} file(s), "
          f"{checked} php listing(s) checked"
          + (f", {unprovenanced} without provenance" if require_provenance else ""))
    return 1 if (failures or unprovenanced) else 0


if __name__ == "__main__":
    args = [a for a in sys.argv[1:] if a != "--require-provenance"]
    raise SystemExit(main(args[0] if args else "book/src",
                          require_provenance="--require-provenance" in sys.argv[1:]))
