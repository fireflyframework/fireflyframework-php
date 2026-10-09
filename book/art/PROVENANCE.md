# Book artwork provenance

The front and back covers belong to the shared Firefly Framework book collection,
prepared on 2026-10-08. Canonical delivery: `Framework-Brand-Kit/11-Books/php/`.
They use the official Firefly Framework identity (A2 treatment) and Manrope
letterforms converted to vector paths. They contain no live font dependency.

The source SVGs and delivered PNGs are 1500 × 1850 pixels, matching the book's
7.5 × 9.25 inch trim. English uses `cover` and `back-cover`; Spanish uses
`cover-es` and `back-cover-es`. Both are full pages in PDF and explicit bookends
in the EPUB reading order. Edition manifests supply accessible alternative text.

`book/build/gen_cover.py` validates this canonical set without changing it.
An explicit `--render` recreates the PNGs from these SVGs with CairoSVG; it never
recreates the retired cover design or substitutes machine-local fonts. Raster
bytes can vary between renderers, so refresh the hashes below if replacing the
delivered PNGs. Publication checksums identify the actual PDF/EPUB bytes.

## Delivered asset SHA-256

| Asset | SHA-256 |
|---|---|
| `cover.svg` | `d8faa683578c0297f57f8d1230daff157cf22d4d8c1cae3d4d33ccd55c800f04` |
| `cover.png` | `6b9dd580be852e410a7c76ff8dbbc01104390780f1b731477059b8860216eab2` |
| `cover-es.svg` | `d4a63f98680517a1fe68511b76f1125f72ad2d1e393def3c875c3117e706142e` |
| `cover-es.png` | `de603dcb41f1ae8ce2881012ffa3e5fed4e6e5871569eff247bca833467402d9` |
| `back-cover.svg` | `64a6b78c9ec4c23354ab0420579e9087a9b742b105617482cdbad96080ff290b` |
| `back-cover.png` | `da2e633783f90d47a18152d917271fa44e38ef5ba62a9e8ebd6beac2405b8c16` |
| `back-cover-es.svg` | `4fb65c27c3c586eba36374c54a6c664b9b780b5e9c6e16d5f9fbaefc9a7ee192` |
| `back-cover-es.png` | `2c2b9db429bbba63e9f30a6197167574cf022b50b00cca0ae04e2ab06f89c4cb` |

## Documentation and interior diagram identity

The 2026-10-08 identity pass applies shared ink (`#10110f`), warm neutral
(`#f3f1eb` / `#dedbd2`) and amber (`#ffb34a`) surfaces. Dark amber (`#8a5714`)
is reserved for readable lines and text on white. Existing blue, green and rust
status/callout colors and third-party marks retain their semantic distinction.

`diagram-branding.json` inventories the diagrams and records SHA-256 fingerprints
of their original non-paint structure. These fingerprints cover every technical
label, path, arrow and layout attribute. Only paint, the old decorative raster
mark and the added footer are excluded. The original viewBox is recorded too.
The footer adds 7.8% of the original width below the content; its outlined family
lockup is embedded with unique SVG IDs and does not cover or scale any original
shape. Screen readers retain the diagram's original title/description.

The canonical lockup is `docs/assets/larafly-logo-light.svg`,
from `Framework-Brand-Kit/12-Frameworks/php/`.
The documentation favicon and small diagram marks use the official kit's
`02-Icons/favicon.svg`. No legacy snake/insect raster is regenerated.

Run `book/.venv/bin/python book/build/brand_diagrams.py --check` to validate
identity, geometry, text and mirrors without writing. Omit `--check` to apply
the idempotent palette/footer pass to a reviewed original source. An intentional
technical diagram edit requires review and updating its fingerprint; never reset
fingerprints merely to make a failing check pass.

Book interiors use the same ink, paper and amber theme. Blue informational,
green tip, rust warning and native Spring/Laravel callout colors remain semantic.

All nine hand-authored diagrams under `docs/assets/diagrams/` are mirrored
byte-for-byte into `book/art/figures/`. There is no Mermaid/PlantUML export step.

For PDF only, `book/build/pdf.py` rasterizes the small canonical footer lockup
at 800 pixels wide before WeasyPrint renders it. This preserves the approved
gradient-y and clipped paths that WeasyPrint otherwise simplifies. Diagram
labels and technical shapes remain selectable text and vectors. Public SVGs
and EPUBs keep the outlined vector logo. Gradients whose stops are the same
color use identical solid paint so WeasyPrint does not drop header backgrounds.

### Outbox layout correction

The `outbox-flow.svg` commit bar previously covered the final pre-commit labels.
Its bar and atomic-commit caption now sit below both branches and `pg_notify`.
The transaction frame grows downward by 70 SVG units; the separate-consumer
section moves down by the same amount into existing bottom whitespace. All
text, arrow connections, width and overall viewBox remain unchanged. Both
mirrors match. The manifest retains the previous structure hash and explains
the intentional layout revision; a geometry guard keeps the commit bar clear
of labels and the consumer frame inside the original canvas.
