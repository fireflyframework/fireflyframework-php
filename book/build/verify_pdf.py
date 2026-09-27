"""Reject generated books whose text extends beyond the PDF page boundaries."""
from __future__ import annotations

import sys

import pdfplumber


def main(paths: list[str]) -> int:
    failures = 0
    for path in paths:
        with pdfplumber.open(path) as document:
            if not document.pages:
                raise ValueError(f'{path}: no PDF pages found')
            for number, page in enumerate(document.pages, 1):
                # Read the content stream, including text wholly outside the MediaBox.
                # Poppler's bounding-box output drops that text before it can be checked.
                outside = [char['text'] for char in page.chars if char['text'].strip() and (
                    char['x0'] < -1 or char['top'] < -1
                    or char['x1'] > page.width + 1 or char['bottom'] > page.height + 1
                )]
                if outside:
                    print(f'FAIL {path}:{number}: off-page text {"".join(outside)!r}')
                    failures += 1
                page.close()
            print(f'{path}: checked {len(document.pages)} pages')
    return 1 if failures else 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
