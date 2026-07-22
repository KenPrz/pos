#!/usr/bin/env python3
"""Build the user manual PDF from the markdown sources.

Workflow: edit the .md files below; run this to (re)build the PDF.

    python3 -m venv docs/user-manual/.venv
    docs/user-manual/.venv/bin/pip install markdown==3.7 pymdown-extensions==10.12 weasyprint==63.1
    docs/user-manual/.venv/bin/python docs/user-manual/build_pdf.py

(Or just `make manual`, which does exactly that.) WeasyPrint needs pango at
runtime; on Debian/Ubuntu: apt-get install libpango-1.0-0 libpangoft2-1.0-0.

Mermaid ```mermaid blocks are rendered to PNGs via `npx @mermaid-js/mermaid-cli`
(needs Node). If Node/npx is missing the block is left as-is instead of failing.
Rendered PNGs are hash-cached in assets/diagrams/ and committed, so CI never
needs to re-render an unchanged diagram.
"""
from pathlib import Path
import re, subprocess, hashlib, sys

ROOT = Path(__file__).parent
DIAGRAMS = ROOT / "assets" / "diagrams"

# Chapter order in the final PDF. Missing files are skipped.
FILES = [
    "user-manual.md",
    "troubleshooting.md",
    "faq.md",
    "glossary.md",
]


def render_mermaid(md: str) -> str:
    """Swap each ```mermaid block for a cached PNG (hash-cached by content, so
    editing a diagram orphans the old png — prune assets/diagrams if that ever
    matters)."""
    def repl(m):
        code = m.group(1)
        png = DIAGRAMS / ("mmd-" + hashlib.sha1(code.encode()).hexdigest()[:10] + ".png")
        if not png.exists():
            DIAGRAMS.mkdir(parents=True, exist_ok=True)
            src = png.with_suffix(".mmd")
            src.write_text(code)
            try:
                subprocess.run(
                    ["npx", "-y", "@mermaid-js/mermaid-cli", "-i", str(src),
                     "-o", str(png), "-b", "white"],
                    check=True, capture_output=True,
                )
            except (FileNotFoundError, subprocess.CalledProcessError) as e:
                print(f"  mermaid render skipped ({e}); leaving code block", file=sys.stderr)
                return m.group(0)
            finally:
                src.unlink(missing_ok=True)
        return f"![diagram](assets/diagrams/{png.name})"

    return re.sub(r"```mermaid\n(.*?)```", repl, md, flags=re.S)


def check_assets(md: str, source: str) -> list[str]:
    """Every ](assets/...) reference must exist on disk. Returns error strings."""
    errors = []
    for ref in re.findall(r"\]\((assets/[^)#?]+)\)", md):
        if not (ROOT / ref).is_file():
            errors.append(f"{source}: missing {ref}")
    return errors


def main():
    if "--selftest" in sys.argv:  # smallest check: the regexes still match
        assert re.search(r"```mermaid\n(.*?)```", "```mermaid\nA-->B\n```", re.S)
        assert check_assets("![x](assets/screenshots/nope.png)", "t") != []
        print("ok"); return

    import markdown
    from weasyprint import HTML

    parts, errors = [], []
    for f in FILES:
        p = ROOT / f
        if not p.exists():
            continue
        text = render_mermaid(p.read_text())
        errors += check_assets(text, f)
        parts.append(text)
    if not parts:
        sys.exit(f"no markdown found in {ROOT} (looked for: {', '.join(FILES)})")
    if errors:
        sys.exit("missing assets:\n  " + "\n  ".join(errors))

    html = markdown.markdown(
        "\n\n".join(parts),
        extensions=["tables", "toc", "fenced_code", "attr_list", "sane_lists", "md_in_html"],
    )
    css = ROOT / "manual.css"
    out = ROOT / "user-manual.pdf"
    HTML(string=html, base_url=str(ROOT)).write_pdf(
        out, stylesheets=[str(css)] if css.exists() else None
    )
    print("wrote", out)


if __name__ == "__main__":
    main()
