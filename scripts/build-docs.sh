#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
BREW_PREFIX="$(brew --prefix 2>/dev/null || echo /opt/homebrew)"
export DYLD_FALLBACK_LIBRARY_PATH="${BREW_PREFIX}/lib:/usr/local/lib:${DYLD_FALLBACK_LIBRARY_PATH:-}"
book/.venv/bin/python -m pytest -q book/tests
book/.venv/bin/python book/build/verify_code.py book/src --require-provenance
book/.venv/bin/python book/build/verify_code.py book/src-es --require-provenance
bash book/build/run.sh
bash book/build/run.sh --config book.es.yaml
book/.venv/bin/python book/build/verify_pdf.py book/dist/larafly-by-example.pdf book/dist/larafly-by-example-es.pdf
book/.venv/bin/mkdocs build --strict

# Keep generated downloads outside docs/ so a standalone MkDocs build needs no books.
book/.venv/bin/python - <<'PY'
import hashlib
import json
from pathlib import Path
import shutil
import subprocess

downloads = Path('site/downloads')
downloads.mkdir(parents=True, exist_ok=True)
checksums = []
for edition in ('larafly-by-example', 'larafly-by-example-es'):
    for extension in ('pdf', 'epub'):
        name = f'{edition}.{extension}'
        target = downloads / name
        shutil.copyfile(Path('book/dist') / name, target)
        checksums.append(f'{hashlib.sha256(target.read_bytes()).hexdigest()}  {name}\n')
revision = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
(downloads / 'build-info.json').write_text(json.dumps({
    'source_commit': revision,
    'source_url': f'https://github.com/fireflyframework/fireflyframework-php/tree/{revision}',
}, indent=2) + '\n')
(downloads / 'SHA256SUMS').write_text(''.join(checksums))
PY
