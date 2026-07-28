"""Generate book/art/cover.svg and cover.png for *LaraFly by Example*.

Reuses the firefly/spark brand motif from docs/assets/larafly-banner.svg
(a glowing firefly abdomen + wings + spark trail — drawn from SVG primitives,
no external font or logo asset required) rather than PyFly's hex-network cover,
so the book cover matches the framework's existing brand language.
"""
from __future__ import annotations
from pathlib import Path

ART = Path(__file__).resolve().parents[1] / "art"
W, H = 1500, 2100  # 7.5 x 9.25 in at 200 dpi

# ---------------------------------------------------------------------------
# Palette (matches docs/assets/larafly-banner.svg)
# ---------------------------------------------------------------------------
BG_TOP    = "#0f172a"
BG_BOTTOM = "#1e293b"
INK       = "#1f2937"
INK_DEEP  = "#111827"
SPARK     = "#fbbf24"
SPARK_LT  = "#fde68a"
WING      = "#e0f2fe"
SLATE     = "#94a3b8"
SLATE_DIM = "#64748b"
CLOUD     = "#cbd5e1"
LIGHT     = "#f8fafc"
LIGHT_DIM = "#e2e8f0"

FONT = "-apple-system,Segoe UI,Roboto,Helvetica Neue,Helvetica,Arial,sans-serif"


def _firefly_glyph(cx: float, cy: float, scale: float) -> str:
    """The firefly/spark emblem, scaled + centered at (cx, cy)."""
    s = scale
    return f'''
    <g transform="translate({cx},{cy}) scale({s})">
      <circle r="58" fill="url(#lf-spark)"/>
      <ellipse cx="-22" cy="-18" rx="30" ry="16" fill="{WING}" opacity="0.55" transform="rotate(-18 -22 -18)"/>
      <ellipse cx="22" cy="-18" rx="30" ry="16" fill="{WING}" opacity="0.55" transform="rotate(18 22 -18)"/>
      <ellipse cx="0" cy="4" rx="15" ry="22" fill="{INK}"/>
      <ellipse cx="0" cy="-8" rx="10" ry="9" fill="{INK_DEEP}"/>
      <ellipse cx="0" cy="22" rx="9" ry="11" fill="{SPARK}"/>
      <ellipse cx="0" cy="22" rx="9" ry="11" fill="{SPARK_LT}" opacity="0.6"/>
      <g fill="{SPARK}">
        <circle cx="-46" cy="46" r="3.5" opacity="0.85"/>
        <circle cx="-62" cy="62" r="2.5" opacity="0.6"/>
        <circle cx="-76" cy="74" r="1.6" opacity="0.35"/>
      </g>
    </g>'''


def build_svg() -> str:
    parts: list[str] = []

    # 1. Background gradient fill
    parts.append(f'<rect width="{W}" height="{H}" fill="url(#lf-bg)"/>')

    # 2. Faint particle texture
    parts.append(f'<g opacity="0.10" stroke="{SLATE}" stroke-width="1.2">')
    for row in range(14):
        for col in range(6):
            gx = 60 + col * 280 + (70 if row % 2 else 0)
            gy = 90 + row * 155
            parts.append(f'<circle cx="{gx}" cy="{gy}" r="2.6"/>')
    parts.append(f'<line x1="0" y1="{H*0.52:.0f}" x2="{W}" y2="{H*0.52:.0f}" stroke-dasharray="2 14"/>')
    parts.append('</g>')

    # 3. Publisher label — top edge
    parts.append(
        f'<text x="{W//2}" y="130" text-anchor="middle" '
        f'fill="{SLATE}" font-size="30" font-weight="600" '
        f'letter-spacing="10" font-family="{FONT}">'
        f'FIREFLY SOFTWARE SOLUTIONS INC.</text>'
    )

    # 4. Firefly glyph — large, centered in the illustration zone
    parts.append(_firefly_glyph(W / 2, 620, 4.6))

    # 5. Divider rules
    RULE_Y = 1130
    parts.append(f'<rect x="110" y="{RULE_Y}" width="1280" height="6" fill="{SPARK}" rx="3"/>')
    parts.append(f'<rect x="110" y="{RULE_Y + 14}" width="1280" height="1.5" '
                  f'fill="{SLATE}" opacity="0.45" rx="1"/>')

    # 6. Title block: "LaraFly" (Fly in spark amber) + "by Example"
    TY = RULE_Y + 150
    parts.append(
        f'<text x="115" y="{TY}" fill="{LIGHT}" font-size="176" font-weight="800" '
        f'font-family="{FONT}" letter-spacing="-4">Lara<tspan fill="{SPARK}">Fly</tspan></text>'
    )
    parts.append(
        f'<text x="120" y="{TY + 130}" fill="{CLOUD}" font-size="86" font-weight="600" '
        f'font-family="{FONT}" letter-spacing="-1">by Example</text>'
    )

    # 7. Subtitle (two lines)
    SUB_Y = TY + 230
    for i, line in enumerate([
        "Hexagonal PHP Microservices",
        "on Laravel 13 with the Firefly Framework",
    ]):
        parts.append(
            f'<text x="120" y="{SUB_Y + i * 58}" fill="{LIGHT_DIM}" font-size="40" '
            f'font-weight="400" font-family="{FONT}">{line}</text>'
        )

    # 8. Footer credit
    parts.append(
        f'<text x="{W - 110}" y="{H - 70}" text-anchor="end" fill="{SLATE_DIM}" '
        f'font-size="24" font-family="{FONT}">Copyright (c) 2026 Firefly Software Solutions Inc.</text>'
    )

    defs = (
        '<defs>'
        '<linearGradient id="lf-bg" x1="0" y1="0" x2="1" y2="1">'
        f'<stop offset="0" stop-color="{BG_TOP}"/><stop offset="1" stop-color="{BG_BOTTOM}"/>'
        '</linearGradient>'
        '<radialGradient id="lf-spark" cx="0.5" cy="0.5" r="0.5">'
        f'<stop offset="0" stop-color="{SPARK_LT}"/><stop offset="0.55" stop-color="{SPARK}"/>'
        f'<stop offset="1" stop-color="{SPARK}" stop-opacity="0"/>'
        '</radialGradient>'
        '</defs>'
    )

    svg = (
        f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" '
        f'viewBox="0 0 {W} {H}">\n{defs}\n' + "\n".join(parts) + "\n</svg>"
    )
    return svg


def _render_png(svg_path: Path, png_path: Path) -> None:
    """Rasterize the cover with cairosvg (book/build/requirements.txt)."""
    import cairosvg
    cairosvg.svg2png(url=str(svg_path), write_to=str(png_path), output_width=W, output_height=H)


def main() -> None:
    ART.mkdir(parents=True, exist_ok=True)
    svg = build_svg()
    (ART / "cover.svg").write_text(svg, encoding="utf-8")
    _render_png(ART / "cover.svg", ART / "cover.png")
    print("wrote cover.svg and cover.png")


if __name__ == "__main__":
    main()
