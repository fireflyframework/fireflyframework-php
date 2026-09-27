"""Reject generated books whose text extends beyond the PDF page boundaries."""
from __future__ import annotations

import subprocess
import sys
import xml.etree.ElementTree as ET


def main(paths: list[str]) -> int:
    failures = 0
    for path in paths:
        result = subprocess.run(
            ['pdftotext', '-bbox', '-enc', 'UTF-8', path, '-'],
            check=True, capture_output=True,
        )
        pages = ET.fromstring(result.stdout).findall('.//{*}page')
        if not pages:
            raise ValueError(f'{path}: no PDF pages found')
        for number, page in enumerate(pages, 1):
            width, height = float(page.attrib['width']), float(page.attrib['height'])
            for word in page.findall('{*}word'):
                box = {key: float(value) for key, value in word.attrib.items()}
                if (box['xMin'] < -1 or box['yMin'] < -1
                        or box['xMax'] > width + 1 or box['yMax'] > height + 1):
                    print(f'FAIL {path}:{number}: off-page text {word.text!r}')
                    failures += 1
        print(f'{path}: checked {len(pages)} pages')
    return 1 if failures else 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
