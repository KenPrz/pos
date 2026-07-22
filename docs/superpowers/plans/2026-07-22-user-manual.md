# POS User Manual Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A committed, screenshot-rich `docs/user-manual/user-manual.pdf` built by the artience-prs pipeline: markdown sources + real Playwright captures of the running app + python-markdown/WeasyPrint, kept current by a CI job.

**Architecture:** `docs/user-manual/` holds four markdown sources, a ported `build_pdf.py` (markdown → WeasyPrint PDF, mermaid → cached PNGs, asset-existence check), a ported `manual.css`, and `capture_screenshots.mjs` (Playwright drives both apps through real flows against the seeded dev stack). `make manual-shots` captures; `make manual` builds; `.github/workflows/manual.yml` rebuilds and auto-commits the PDF on source changes, mirroring `wiki.yml`.

**Tech Stack:** Playwright (chromium, local only) · python-markdown 3.7 + pymdown-extensions 10.12 + WeasyPrint 63.1 (pinned) · @mermaid-js/mermaid-cli via npx · GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-07-22-user-manual-design.md`
**Reference implementation:** `../artience-prs/docs/user-manual/` (read-only; port, don't symlink)

## Global Constraints

- Nothing changes outside `docs/user-manual/`, `Makefile`, `.gitignore`, and `.github/workflows/manual.yml`. `docs/manual/` and `scripts/wiki-sync.sh` are untouched.
- Commit style: repo voice (`Manual: ...`, `Docs: ...`), imperative, **no Co-Authored-By or any attribution trailer** (hard user rule).
- Screenshots: viewport **1280×800, deviceScaleFactor 2**, numbered `NNN-name.png`, overwrite-by-name. Peso amounts and the Manila seed (GRC/RST/CAF) must be what's on screen — capture only against `POS_SEED_CATALOGS=grocery,restaurant,cafe make seed`.
- Pinned build deps everywhere they're installed: `markdown==3.7 pymdown-extensions==10.12 weasyprint==63.1` (if a pin is uninstallable at execution time, bump to the nearest available version and record it in the task report + script docstring — the pin must stay exact in all three places: docstring, Makefile, workflow).
- Environment facts: dev stack via `make dev` (api 8000, register 5174, back office 5175); root `.env` carries a local `POS_DEV_DB_PORT=5434` override — leave it, never commit `.env`. Host has `python3` 3.12 but **no pip3** (use `python3 -m venv`); node v24 exists. Seed logins: admin `admin@pos.test` / `admin-dev-password`; PINs Alice `1111` (cashier), Bob `2222` (supervisor).
- Both frontends have **no data-testids** — Playwright locators must use the exact labels/roles/placeholders given in Task 2's script. Both apps boot through a `'booting'` stage; always wait for screen-specific text, never just networkidle.
- The manual's facts must agree with `docs/manual/*.md` and shipped behavior; where they conflict, the code is the truth and the discrepancy goes in the task report.

---

### Task 1: Pipeline scaffold — build_pdf.py, manual.css, stub manual, `make manual`

**Files:**
- Create: `docs/user-manual/build_pdf.py`
- Create: `docs/user-manual/manual.css`
- Create: `docs/user-manual/user-manual.md` (stub: cover + revision history + chapter 1)
- Modify: `Makefile` (add `manual` target; extend `.PHONY` line)
- Modify: `.gitignore` (add `docs/user-manual/.venv/`, `docs/user-manual/node_modules/`)

**Interfaces:**
- Produces: `python build_pdf.py` builds `docs/user-manual/user-manual.pdf` from `FILES = ["user-manual.md", "troubleshooting.md", "faq.md", "glossary.md"]` (missing files skipped), fails non-zero when a referenced `assets/…` file is missing, renders ```` ```mermaid ```` blocks to `assets/diagrams/mmd-<sha1[:10]>.png` via npx (graceful skip without node). Tasks 4–6 write into these files; Task 7's CI calls this script.

- [ ] **Step 1: Write build_pdf.py**

Port of `../artience-prs/docs/user-manual/build_pdf.py` with one addition (the asset check) and the pinned-deps docstring:

```python
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
```

- [ ] **Step 2: Write manual.css**

Port of artience's stylesheet with the accent moved to the Carbon blue this repo's `DESIGN.md` uses (`#0f62fe`):

```css
@page {
  size: A4;
  margin: 2.2cm 2cm;
  @bottom-center { content: counter(page); font-size: 9pt; color: #888; }
}

body { font-family: system-ui, "Segoe UI", sans-serif; font-size: 11pt; line-height: 1.5; color: #222; }

h1 { page-break-before: always; font-size: 20pt; margin-top: 0; }
h1:first-of-type { page-break-before: avoid; }   /* cover / first page */
h2 { font-size: 15pt; border-bottom: 1px solid #ddd; padding-bottom: 2px; }
h3 { font-size: 12.5pt; }

img { max-width: 100%; }
figure, img { break-inside: avoid; }

table { border-collapse: collapse; width: 100%; font-size: 10pt; break-inside: avoid; }
th, td { border: 1px solid #ccc; padding: 4px 7px; text-align: left; vertical-align: top; }
th { background: #f2f2f2; }

code { font-family: ui-monospace, "SFMono-Regular", monospace; font-size: 9.5pt; }
pre { background: #f6f6f6; padding: 8px 10px; border-radius: 4px; overflow-x: auto; break-inside: avoid; }

blockquote { border-left: 3px solid #0f62fe; margin: 0; padding: 2px 12px; color: #555; background: #edf5ff; }

.toc ul { list-style: none; padding-left: 1em; }
```

- [ ] **Step 3: Write the stub user-manual.md**

Just enough to prove the pipeline (Task 4 replaces the body; keep the cover block verbatim — it survives to the final manual):

```markdown
# POS — User Manual

For the people who run the store: cashiers, supervisors, managers, and the
operator who installs the system. Covers the register and the back office.

## Revision History

| Version | Date | Changes |
| --- | --- | --- |
| 1.0 | 2026-07-22 | First edition: register, back office, troubleshooting, FAQ, glossary. |

# 1. Introduction

Placeholder chapter — replaced as the manual is written. The build pipeline is
the deliverable of this commit.
```

- [ ] **Step 4: Makefile target + .gitignore**

Append to `.gitignore`:

```
docs/user-manual/.venv/
docs/user-manual/node_modules/
```

In `Makefile`: add `manual` (and `manual-shots`, wired in Task 2 — declare both in `.PHONY` now) after the `e2e` target:

```make
manual: ## Build docs/user-manual/user-manual.pdf (host python3; pinned deps into a local venv)
	@test -d docs/user-manual/.venv || python3 -m venv docs/user-manual/.venv
	@docs/user-manual/.venv/bin/pip install -q markdown==3.7 pymdown-extensions==10.12 weasyprint==63.1
	docs/user-manual/.venv/bin/python docs/user-manual/build_pdf.py
```

Add `manual manual-shots` to the `.PHONY` line.

- [ ] **Step 5: Verify**

```bash
python3 docs/user-manual/build_pdf.py --selftest    # → ok
make manual
```

Expected: `wrote .../user-manual.pdf`; open-able PDF with the cover and stub chapter. If WeasyPrint fails at import/run with a missing-library error, install the system deps from the docstring (`sudo apt-get install -y libpango-1.0-0 libpangoft2-1.0-0`) and record that in the report. Then prove the asset check: temporarily add `![x](assets/screenshots/nope.png)` to the stub, rerun, expect exit non-zero with `missing assets`, remove it.

- [ ] **Step 6: Commit**

```bash
git add docs/user-manual/build_pdf.py docs/user-manual/manual.css docs/user-manual/user-manual.md docs/user-manual/user-manual.pdf Makefile .gitignore
git commit -m "Manual: WeasyPrint build pipeline ported from artience-prs"
```

---

### Task 2: capture_screenshots.mjs + back-office captures

**Files:**
- Create: `docs/user-manual/capture_screenshots.mjs` (complete script — both legs)
- Create: `docs/user-manual/package.json`
- Modify: `Makefile` (add `manual-shots` target)
- Create (output): `docs/user-manual/assets/screenshots/021-…033-*.png` (back-office leg)

**Interfaces:**
- Consumes: running dev stack + fresh `POS_SEED_CATALOGS=grocery,restaurant,cafe make seed`; admin API at `http://127.0.0.1:8000/api/v1`.
- Produces: the full figure inventory (both legs) with these exact filenames — the manual chapters (Tasks 4–5) reference them verbatim:

| File | Shows |
| --- | --- |
| 001-activation | Register activation screen (code entry) |
| 002-pin | Enter PIN screen |
| 003-open-shift | Open shift (float) |
| 004-retail-empty | Retail sale screen, empty cart |
| 005-retail-cart | Cart with scanned lines |
| 006-retail-discount | Discount panel open |
| 007-retail-discounted | Cart with 10% off applied |
| 008-retail-tender | Cash tender phase |
| 009-retail-receipt | Payment complete + receipt |
| 010-refunds | Refunds screen |
| 011-floor | Food floor (tabs) |
| 012-menu-grid | Food sale screen (menu grid) |
| 013-modifier-sheet | Modifier picker |
| 014-tab-cart | Tab with modified lines |
| 015-split | Split-bill prompt |
| 016-transfer | Transfer "Send to" sheet |
| 017-close-shift | Close shift (count the drawer) |
| 018-z-report | Drawer reconciled + Z-report + variance approval prompt |
| 020-disabled | Terminal disabled (lockout) |
| 021-bo-login | Back-office sign in |
| 022-bo-today | Today landing |
| 023-bo-catalog | Catalog list |
| 024-bo-product | Product editor |
| 025-bo-users | Users section |
| 027-bo-locations | Locations tab |
| 028-bo-registers | Registers tab |
| 029-bo-register-editor | Register editor (Issue activation code visible) |
| 030-bo-activation-code | Issued code displayed |
| 031-bo-report-sales | Sales report |
| 032-bo-report-stock | Stock report |
| 033-bo-audit | Audit log |

(019 and 026 are intentionally unassigned — the numbering gaps are documented in the script header. Do not renumber.)

- [ ] **Step 1: package.json + Makefile target**

`docs/user-manual/package.json`:

```json
{
  "name": "pos-user-manual-capture",
  "private": true,
  "type": "module",
  "devDependencies": {
    "playwright": "^1.49.0"
  }
}
```

Makefile, after `manual`:

```make
manual-shots: ## Capture user-manual screenshots (needs `make dev` up + POS_SEED_CATALOGS=grocery,restaurant,cafe make seed)
	cd docs/user-manual && npm install --no-fund --no-audit && npx playwright install chromium
	node docs/user-manual/capture_screenshots.mjs
```

- [ ] **Step 2: Write the capture script (complete — both legs)**

Every locator string below comes from the current component sources (`frontend/web/src/register/*`, `frontend/back-office/src/admin/*`); if one misses at runtime, fix the locator against the live DOM and record the change in the task report.

```js
// Captures real screenshots of both POS apps for the user manual.
//   Preconditions: `make dev` up, and a FRESH `POS_SEED_CATALOGS=grocery,restaurant,cafe make seed`.
//   Run: node docs/user-manual/capture_screenshots.mjs [--only=bo|register]
// Re-runnable; overwrites by name. Figure numbers 019/026 are intentionally
// unassigned (kept for insertion room); do not renumber existing figures.
import { mkdirSync, readdirSync, readFileSync, existsSync } from 'node:fs';
import { chromium } from 'playwright';

const API = 'http://127.0.0.1:8000/api/v1';
const REGISTER = process.env.POS_REGISTER_URL || 'http://127.0.0.1:5174';
const BO = process.env.POS_BO_URL || 'http://127.0.0.1:5175';
const OUT = new URL('./assets/screenshots/', import.meta.url).pathname;
const ONLY = (process.argv.find(a => a.startsWith('--only=')) || '').split('=')[1];
mkdirSync(OUT, { recursive: true });

// ---- seeded credentials (dev-only, printed by the seeder; never real) ----
const ADMIN = { email: 'admin@pos.test', password: 'admin-dev-password' };
const PIN_CASHIER = '1111';    // Alice
const PIN_SUPERVISOR = '2222'; // Bob

// ---- tiny admin-API client (device enrolment is API-driven where the flow
//      isn't itself the thing being photographed) ----
async function api(path, { method = 'GET', token, body } = {}) {
  const res = await fetch(`${API}${path}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status}: ${await res.text()}`);
  return (await res.json()).data;
}

async function adminToken() {
  return (await api('/admin/login', { method: 'POST', body: ADMIN })).token;
}

async function findRegister(admin, locationCode, name) {
  const locations = (await api('/admin/locations', { token: admin })).items;
  const location = locations.find(l => l.code === locationCode);
  if (!location) throw new Error(`no location ${locationCode} — reseed with all three catalogs`);
  const registers = (await api('/admin/registers', { token: admin })).items;
  const register = registers.find(r => r.location_id === location.id && r.name === name);
  if (!register) throw new Error(`no register ${name} at ${locationCode}`);
  return register;
}

const issueCode = (admin, id) =>
  api(`/admin/registers/${id}/activation-code`, { method: 'POST', token: admin });
const activate = (code) =>
  api('/registers/activate', { method: 'POST', body: { activation_code: code } });

async function shot(page, file) {
  await page.waitForTimeout(400); // let transitions settle
  await page.screenshot({ path: `${OUT}${file}.png` });
  console.log('  ✓', file);
}

const browser = await chromium.launch();

// ============================== REGISTER LEG ==============================
async function registerLeg() {
  const admin = await adminToken();

  // --- retail (GRC Till 1), enrolled through the real activation UI ---
  const grcTill1 = await findRegister(admin, 'GRC', 'Till 1');
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  await page.goto(REGISTER);
  await page.getByText('Activation code').waitFor();
  await shot(page, '001-activation');

  const issued = await issueCode(admin, grcTill1.id);
  await page.getByPlaceholder('XXXXX-XXXXX').fill(issued.activation_code);
  await page.getByRole('button', { name: 'Activate' }).click();

  await page.getByText('Enter PIN').waitFor();
  await shot(page, '002-pin');
  await page.getByPlaceholder('••••').fill(PIN_SUPERVISOR);
  await page.getByRole('button', { name: 'Clock in' }).click();

  await page.getByText('Open shift').waitFor();
  await shot(page, '003-open-shift');
  await page.getByLabel('Opening float').fill('200.00');
  await page.getByRole('button', { name: 'Open drawer' }).click();

  const scan = page.getByPlaceholder('Scan or type a barcode…');
  await scan.waitFor();
  await shot(page, '004-retail-empty');

  for (const barcode of ['4809990000016', '4809990000023', '4809990000030']) {
    await scan.fill(barcode);
    await scan.press('Enter');
    await page.waitForTimeout(300);
  }
  await shot(page, '005-retail-cart');

  await page.getByRole('button', { name: 'Discount' }).click();
  await page.getByPlaceholder('Reason (required)…').waitFor();
  await shot(page, '006-retail-discount');
  await page.getByPlaceholder('Reason (required)…').fill('manager comp');
  await page.getByRole('button', { name: '10% off' }).click();
  await page.waitForTimeout(300);
  await shot(page, '007-retail-discounted');

  await page.getByRole('button', { name: /^Pay — / }).click();
  await page.getByRole('group', { name: 'Payment method' }).waitFor();
  await page.getByLabel(/^Cash tendered/).fill('500.00');
  await shot(page, '008-retail-tender');
  await page.getByRole('button', { name: 'Take payment' }).click();
  await page.getByText('Payment complete').waitFor();
  await shot(page, '009-retail-receipt');
  await page.getByRole('button', { name: 'New sale' }).click();

  await page.getByRole('button', { name: 'Refunds' }).click();
  await page.waitForTimeout(400);
  await shot(page, '010-refunds');
  await page.getByRole('button', { name: 'Register' }).click();

  // --- lockout: issuing a new code kills this device's token ---
  await issueCode(admin, grcTill1.id);
  await scan.fill('4809990000016');
  await scan.press('Enter'); // 401 -> stage 'disabled'
  await page.getByText('Terminal disabled').waitFor();
  await shot(page, '020-disabled');
  await ctx.close();

  // --- food (RST Till 1), enrolled via API + injected token so the manual's
  //     activation figures stay the retail ones ---
  const rstTill1 = await findRegister(admin, 'RST', 'Till 1');
  const rstIssued = await issueCode(admin, rstTill1.id);
  const activated = await activate(rstIssued.activation_code);
  const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 });
  const page2 = await ctx2.newPage();
  await ctx2.addInitScript(([token, info]) => {
    localStorage.setItem('pos.device_token', token);
    localStorage.setItem('pos.register_info', JSON.stringify(info));
  }, [activated.device_token, activated.register]);

  await page2.goto(REGISTER);
  await page2.getByText('Enter PIN').waitFor();
  await page2.getByPlaceholder('••••').fill(PIN_CASHIER);
  await page2.getByRole('button', { name: 'Clock in' }).click();
  await page2.getByText('Open shift').waitFor();
  await page2.getByLabel('Opening float').fill('200.00');
  await page2.getByRole('button', { name: 'Open drawer' }).click();
  await page2.waitForTimeout(600);
  await shot(page2, '012-menu-grid');

  await page2.getByRole('button', { name: 'Tabs' }).click();
  await page2.waitForTimeout(400);
  await shot(page2, '011-floor');

  await page2.getByRole('button', { name: 'New tab' }).click();
  await page2.getByPlaceholder('Table (optional)…').fill('T1');
  await page2.getByRole('button', { name: 'Open tab' }).click();
  await page2.waitForTimeout(400);

  await page2.getByRole('button', { name: 'Chicken Adobo' }).click();
  await page2.getByRole('button', { name: 'Garlic Rice' }).click();
  await page2.getByRole('button', { name: 'Extra Egg' }).click();
  await shot(page2, '013-modifier-sheet');
  await page2.getByRole('button', { name: /^Add/ }).click();
  await page2.waitForTimeout(300);
  await page2.getByRole('button', { name: 'Halo-Halo' }).click().catch(() => {});
  await page2.waitForTimeout(300);
  await shot(page2, '014-tab-cart');

  await page2.getByRole('button', { name: 'Split bill' }).click();
  await page2.getByRole('group', { name: 'Number of checks' }).waitFor();
  await shot(page2, '015-split');
  await page2.getByRole('button', { name: 'Cancel' }).click();

  await page2.getByRole('button', { name: 'Tabs' }).click();
  await page2.getByRole('button', { name: 'Transfer' }).first().click();
  await page2.getByText('Send to').waitFor();
  await shot(page2, '016-transfer');
  await page2.keyboard.press('Escape');

  await page2.getByRole('button', { name: 'Register' }).click();
  await page2.getByRole('button', { name: 'Close shift' }).click();
  await page2.getByText('Close shift — count the drawer').waitFor();
  await shot(page2, '017-close-shift');
  await page2.getByLabel('Counted cash').fill('150.00'); // deliberately short -> variance needs approval
  await page2.getByRole('button', { name: 'Close', exact: true }).click();
  await page2.getByText('Drawer reconciled').waitFor();
  await shot(page2, '018-z-report');
  await ctx2.close();
}

// ============================== BACK-OFFICE LEG ==============================
async function boLeg() {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  await page.goto(BO);
  await page.getByText('Sign in').first().waitFor();
  await shot(page, '021-bo-login');
  await page.locator('#admin-email').fill(ADMIN.email);
  await page.locator('#admin-password').fill(ADMIN.password);
  await page.getByRole('button', { name: 'Sign in' }).click();

  const nav = (name) => page.getByRole('navigation', { name: 'Sections' }).getByRole('button', { name });

  await page.getByText('Today').first().waitFor();
  await page.waitForTimeout(800);
  await shot(page, '022-bo-today');

  await nav('Catalog').click();
  await page.waitForTimeout(600);
  await shot(page, '023-bo-catalog');
  await page.getByText('Chicken Adobo').first().click();
  await page.waitForTimeout(400);
  await shot(page, '024-bo-product');

  await nav('Users').click();
  await page.waitForTimeout(600);
  await shot(page, '025-bo-users');

  await nav('Locations & Registers').click();
  await page.waitForTimeout(600);
  await shot(page, '027-bo-locations');
  await page.getByRole('tab', { name: 'Registers' }).click();
  await page.waitForTimeout(400);
  await shot(page, '028-bo-registers');

  // RST Till 2 is not used by the register leg, so revoking its token is harmless.
  await page.getByText('Till 2').last().click();
  await page.getByRole('button', { name: 'Issue activation code' }).waitFor();
  await shot(page, '029-bo-register-editor');
  await page.getByRole('button', { name: 'Issue activation code' }).click();
  await page.getByRole('button', { name: 'Issue code' }).click();
  await page.getByText('Activation code — single use').waitFor();
  await shot(page, '030-bo-activation-code');

  await nav('Reports').click();
  await page.waitForTimeout(800);
  await shot(page, '031-bo-report-sales');
  await page.getByRole('tab', { name: 'Stock' }).click();
  await page.waitForTimeout(600);
  await shot(page, '032-bo-report-stock');

  await nav('Audit').click();
  await page.waitForTimeout(600);
  await shot(page, '033-bo-audit');
  await ctx.close();
}

// ============================== RUN + ORPHAN CHECK ==============================
try {
  if (ONLY !== 'bo') await registerLeg();
  if (ONLY !== 'register') await boLeg();
} finally {
  await browser.close();
}

// Referenced-vs-captured report (spec acceptance): read the four md sources.
const ROOT = new URL('./', import.meta.url).pathname;
const referenced = new Set();
for (const f of ['user-manual.md', 'troubleshooting.md', 'faq.md', 'glossary.md']) {
  if (!existsSync(ROOT + f)) continue;
  for (const m of readFileSync(ROOT + f, 'utf8').matchAll(/\]\(assets\/screenshots\/([^)]+)\)/g)) {
    referenced.add(m[1]);
  }
}
const captured = new Set(readdirSync(OUT).filter(f => f.endsWith('.png')));
const missing = [...referenced].filter(f => !captured.has(f));
const unreferenced = [...captured].filter(f => !referenced.has(f));
if (missing.length) console.error('referenced but MISSING:', missing.join(', '));
if (unreferenced.length) console.log('captured but unreferenced (fine while chapters are unwritten):', unreferenced.join(', '));
console.log(`done — ${captured.size} screenshots on disk, ${referenced.size} referenced`);
process.exitCode = missing.length ? 1 : 0;
```

- [ ] **Step 3: Run the back-office leg**

```bash
make dev                                            # if not up
POS_SEED_CATALOGS=grocery,restaurant,cafe make seed
cd docs/user-manual && npm install --no-fund --no-audit && npx playwright install chromium && cd ../..
node docs/user-manual/capture_screenshots.mjs --only=bo
```

Expected: 12 `✓` lines (021–033), exit 0, PNGs in `assets/screenshots/`. Open two or three and confirm they show the Manila seed (₱ prices, GRC/RST/CAF names) and are not blank/booting frames. Fix any locator that misses (record fixes).

- [ ] **Step 4: Commit**

```bash
git add docs/user-manual/capture_screenshots.mjs docs/user-manual/package.json docs/user-manual/assets/screenshots/0*.png Makefile
git commit -m "Manual: Playwright capture script; back-office screenshots"
```

---

### Task 3: Register-leg captures

**Files:**
- Modify (if locators need fixes): `docs/user-manual/capture_screenshots.mjs`
- Create (output): `docs/user-manual/assets/screenshots/001-…020-*.png` (register leg)

**Interfaces:**
- Consumes: Task 2's script; a fresh seed (the lockout capture revoked nothing yet on a fresh DB, and the retail flow expects the anchor barcodes 4809990000016/23/30 present).

- [ ] **Step 1: Reseed and run the register leg**

```bash
POS_SEED_CATALOGS=grocery,restaurant,cafe make seed
node docs/user-manual/capture_screenshots.mjs --only=register
```

Expected: 18 `✓` lines (001–018, 020), exit 0; the orphan report lists everything as "captured but unreferenced" because the chapters aren't written yet — that is fine ("referenced but MISSING" is the only failure signal). This leg drives the real activation UI, sale flow, tab flow, and lockout — expect to iterate on locators/timing; keep fixes minimal and record each in the report. Eyeball every register PNG: cart shows peso amounts, adobo has its modifiers, the disabled screen says "Terminal disabled".

- [ ] **Step 2: Re-run to prove idempotence**

Reseed + rerun the full script (`no --only`). Expected: all 30 figures captured, overwritten cleanly, no stale states (a second activation for GRC Till 1 works because reseed reset everything).

- [ ] **Step 3: Commit**

```bash
git add docs/user-manual/capture_screenshots.mjs docs/user-manual/assets/screenshots/
git commit -m "Manual: register-flow screenshots (activation, sale, tabs, close, lockout)"
```

---

### Task 4: Manual chapters 1–3 and 8–13 (overview + back office)

**Files:**
- Modify: `docs/user-manual/user-manual.md` (replace stub body; keep cover + revision history)

**Interfaces:**
- Consumes: figures 001/002/020/021–033; facts from `docs/manual/00-getting-started.md`, `docs/manual/03-manager-guide.md`, `docs/manual/04-operator-guide.md`, `docs/00-overview.md`, `docs/05-rbac.md`.
- Produces: chapters that Task 5 continues (numbering fixed: 1–14 as listed in the spec).

- [ ] **Step 1: Write chapters 1–3**

Follow the artience skeleton (each chapter: short overview prose → numbered walkthroughs → figures → a "Common issues" table where the spec's troubleshooting facts apply). Required content, per chapter — write real prose, in the existing manual's voice (read `docs/manual/00-getting-started.md` first and stay consistent with it):

- **1. Introduction** — audience (cashier/supervisor/manager/operator), how the manual is organized (registers first, back office second), conventions (buttons in **bold**, peso amounts, "till" = register).
- **2. System overview** — one order model serving retail and food service; the three surfaces table (register / back office / API) adapted from `00-getting-started.md`; roles and per-location RBAC (cashier vs supervisor vs admin flag); locations and the Manila demo data used in every figure. Two mermaid diagrams, exactly these:

```` ```mermaid
flowchart LR
  O[Open order] --> L[Add lines] --> P[Take payment] --> C[Closed]
  C --> R[Refund - new rows, original untouched]
``` ````

```` ```mermaid
flowchart LR
  A[Open shift - count float] --> B[Sales and cash movements] --> Z[Z-report] --> X[Close - count drawer]
  X --> V{Variance over threshold?}
  V -- yes --> S[Supervisor approval from another register]
  V -- no --> D[Done]
``` ````

- **3. Getting started** — activating a terminal: figure `![Figure 3.1 — Activation screen](assets/screenshots/001-activation.png)`; the code is issued in the back office, single-use, 7-day expiry; what reissue does (kills the old token and staff sessions → forward-reference the lockout figure in ch. 11). Signing in: PIN at the till (`![Figure 3.2 — Enter PIN](assets/screenshots/002-pin.png)`), email/password in the back office (`![Figure 3.3 — Back-office sign in](assets/screenshots/021-bo-login.png)`).

- [ ] **Step 2: Write chapters 8–13**

- **8. The back office** — layout (sidebar sections, location selector, sign out), Today landing: figure 022. Facts from `03-manager-guide.md`.
- **9. Catalog** — categories/products/variants (SKU, barcode, price, cost, tax rate, tracked), modifier groups, discounts, tax rates; archive-never-delete (no delete anywhere under admin). Figures 023, 024.
- **10. Users and roles** — hiring with a PIN, per-location role assignment (roles are a full-set replace), the admin flag spanning locations. Figure 025.
- **11. Locations and registers** — location settings (timezone, VAT-inclusive pricing, receipt copy), register mode (retail vs food) and what it changes on the till, issuing/reissuing activation codes: figures 027, 028, 029, 030, and the till-side consequence `![Figure 11.5 — a reissued code locks the old terminal out](assets/screenshots/020-disabled.png)`.
- **12. Reports** — sales by day and by user are **ledger-basis** (payments and refunds), by category is **line-basis** (order lines); they don't reconcile and that's by design — state it the way `03-manager-guide.md` does. Stock and low-stock. Figures 031, 032.
- **13. Audit log** — what's recorded (every admin write, register activations), filtering by action/entity. Figure 033.

- [ ] **Step 3: Build and check**

```bash
make manual
python3 - <<'EOF'
import re, pathlib
root = pathlib.Path('docs/user-manual')
refs = set()
for f in ['user-manual.md','troubleshooting.md','faq.md','glossary.md']:
    p = root/f
    if p.exists():
        refs |= set(re.findall(r'\]\(assets/screenshots/([^)]+)\)', p.read_text()))
missing = [r for r in refs if not (root/'assets/screenshots'/r).is_file()]
print('missing:', missing or 'none')
EOF
```

Expected: PDF builds (the build itself fails on a missing asset, so `make manual` green is the real gate); mermaid PNGs appear in `assets/diagrams/`. Skim the PDF: chapter breaks on every `# N.` heading, figures render, tables fit.

- [ ] **Step 4: Commit**

```bash
git add docs/user-manual/user-manual.md docs/user-manual/user-manual.pdf docs/user-manual/assets/diagrams/
git commit -m "Manual: overview and back-office chapters"
```

---

### Task 5: Manual chapters 4–7 and 14 (register + desktop shell)

**Files:**
- Modify: `docs/user-manual/user-manual.md`

**Interfaces:**
- Consumes: figures 003–018; facts from `docs/manual/01-cashier-guide.md`, `docs/manual/02-supervisor-guide.md`, `frontend/native/README.md`.

- [ ] **Step 1: Write chapters 4–7**

Same skeleton; required content:

- **4. The register** — one screen, staged: activation → PIN → shift → selling; retail mode (scan-first, figure 004) vs food mode (menu grid + Tabs, figure 012); who sets mode (back office, ch. 11); the top bar (staff name, Clock out, Refunds, Close shift).
- **5. Retail selling** — walkthroughs with figures 004–010: scan or type a barcode (weighed items sell fractional quantities — the per-kg items in the seed); the cart; discounts (reason required; some need a supervisor); voiding a line; **Pay** → tender (cash with change math, figure 008; card with terminal reference); payment complete + receipt + Print (figure 009); refunds by receipt number with per-line restock (figure 010). Common issues table: unknown barcode, discount refused (permission), refund exceeds remaining.
- **6. Food service** — tabs and the floor (figure 011), opening a tab with a table name, the menu grid, modifiers (required groups like Rice; repeat-legal add-ons; "No Rice" reduces the price — signed deltas; figure 013), courses and firing, editing quantity on a fired line, transferring a tab to another register (figure 016 — the receiving drawer owns the money), splitting (figure 015 — totals always sum back exactly; fractional quantities on split lines are normal).
- **7. Shifts and cash** — open with a counted float (figure 003), cash movements (payout/deposit with reasons), the Z-report **before** close (closing revokes the register's sessions — the reason, stated plainly), closing and counting (figure 017), variance and approval (figure 018): over-threshold variances need a supervisor, approved from a *different* register at the same location; the till that just closed can't approve its own.

- [ ] **Step 2: Write chapter 14**

- **14. The desktop shell and printing** — the register also ships as a Tauri desktop app hosting the same UI; it adds the receipt printer and cash-drawer seam; today the driver is a **mock** (state this honestly, per `frontend/native/README.md`) — a browser tab prints via the system print dialog instead. No-sale drawer opens need the drawer permission.

- [ ] **Step 3: Build and commit**

```bash
make manual
git add docs/user-manual/user-manual.md docs/user-manual/user-manual.pdf
git commit -m "Manual: register chapters (selling, food service, shifts, shell)"
```

---

### Task 6: Troubleshooting, FAQ, glossary + final assembly

**Files:**
- Create: `docs/user-manual/troubleshooting.md`
- Create: `docs/user-manual/faq.md`
- Create: `docs/user-manual/glossary.md`

**Interfaces:**
- Consumes: everything prior; the chapter numbering continues (# 15 Troubleshooting, # 16 FAQ, # 17 Glossary) so the PDF's page-break-per-h1 styling holds.

- [ ] **Step 1: Write troubleshooting.md**

`# 15. Troubleshooting` — symptom → cause → fix tables covering at least: "Terminal disabled" screen (a new code was issued; get it from the back office, figure cross-ref 020); every request 401s mid-shift (register token reissued, or shift-close revoked sessions); can't approve a variance from the till that closed (approve from another register at the location); activation code rejected (expired after 7 days / already used — issue a fresh one); receipt won't print (browser vs shell; mock driver status); register shows System healthy but no catalog (device token vs staff session distinction).

- [ ] **Step 2: Write faq.md**

`# 16. FAQ` — at minimum: why don't the day and category reports reconcile (ledger vs line basis — by design); what does VAT-inclusive pricing mean for totals and receipts (the shelf price *is* the total; tax shown is derived); why can't I delete a product (archive-never-delete); why did my discount need a supervisor; what happens to an open tab when a register is reissued; can two people use one till (PIN sessions are per-person; clock out between).

- [ ] **Step 3: Write glossary.md**

`# 17. Glossary` — two-column table defining at least: till/register, device token, activation code, staff session, PIN, shift, float, cash movement, Z-report, variance, tab, `table_ref`, course/fire, modifier / modifier group / signed delta, split, transfer, restock, ledger basis vs line basis, VAT-inclusive, SKU, barcode, archive.

- [ ] **Step 4: Full rebuild + orphan check**

```bash
make manual
```

Do **not** re-capture. Run the reference check standalone (the python snippet from Task 4 Step 3) and confirm: `missing: none`, and no figure on disk is unreferenced except none — every one of the 30 PNGs should now be referenced by some chapter; if one isn't, either reference it or delete it and record why. Skim the final PDF end-to-end once: TOC-less is fine (chapters are the navigation), all 17 h1 chapters present, no widowed figures.

- [ ] **Step 5: Commit**

```bash
git add docs/user-manual/troubleshooting.md docs/user-manual/faq.md docs/user-manual/glossary.md docs/user-manual/user-manual.pdf
git commit -m "Manual: troubleshooting, FAQ, and glossary"
```

---

### Task 7: CI workflow + repo docs record

**Files:**
- Create: `.github/workflows/manual.yml`
- Modify: `docs/06-roadmap.md` (append record), `CLAUDE.md` (Status paragraph + `make manual`/`manual-shots` in the targets table)

- [ ] **Step 1: Write the workflow**

Mirrors `wiki.yml`'s shape (checkout → build → commit-back). The paths filter excludes the two generated outputs so the bot's own commit never retriggers:

```yaml
# .github/workflows/manual.yml
name: Manual

on:
  push:
    branches: [main]
    paths:
      - docs/user-manual/**
      - "!docs/user-manual/user-manual.pdf"
      - "!docs/user-manual/assets/diagrams/**"
  workflow_dispatch:

permissions:
  contents: write

jobs:
  build:
    name: Rebuild user-manual.pdf
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5

      - uses: actions/setup-python@v5
        with:
          python-version: "3.12"

      - name: WeasyPrint system deps
        run: sudo apt-get update -qq && sudo apt-get install -y -qq libpango-1.0-0 libpangoft2-1.0-0 fonts-dejavu-core

      - name: Build PDF (includes referenced-asset check)
        run: |
          pip install markdown==3.7 pymdown-extensions==10.12 weasyprint==63.1
          python docs/user-manual/build_pdf.py

      - name: Commit rebuilt PDF
        run: |
          git config user.name "manual-build"
          git config user.email "manual-build@users.noreply.github.com"
          git add docs/user-manual/user-manual.pdf docs/user-manual/assets/diagrams
          git diff --cached --quiet && { echo "pdf unchanged"; exit 0; }
          git commit -m "Rebuild user manual PDF from ${GITHUB_SHA::7}"
          git push
```

- [ ] **Step 2: Validate the workflow locally**

```bash
command -v actionlint >/dev/null && actionlint .github/workflows/manual.yml || npx -y action-validator .github/workflows/manual.yml 2>/dev/null || python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/manual.yml')); print('yaml ok')"
```

Expected: parses clean (whichever validator is available; the python yaml check is the floor — note that PyYAML may need `pip install pyyaml` in the venv). Simulate the build step exactly as CI runs it: `docs/user-manual/.venv/bin/python docs/user-manual/build_pdf.py` → succeeds. Full end-to-end CI can only be observed after merge to main; the workflow_dispatch trigger exists so it can be exercised manually then — say so in the report.

- [ ] **Step 3: Docs record**

- `docs/06-roadmap.md`: append a short section in the file's voice: user manual shipped — `docs/user-manual/` (4 md sources, 30 staged Playwright screenshots of the Manila seed, WeasyPrint PDF, `make manual`/`make manual-shots`, CI auto-rebuild via `manual.yml`); pipeline ported from a sibling project's proven implementation.
- `CLAUDE.md`: add `make manual` / `make manual-shots` rows to the targets table and one Status line pointing at `docs/user-manual/`.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/manual.yml docs/06-roadmap.md CLAUDE.md
git commit -m "Manual: CI rebuild workflow; record in roadmap and CLAUDE.md"
```

- [ ] **Step 5: Final sweep**

```bash
git log --oneline main..HEAD     # story: pipeline -> captures -> chapters -> aux -> CI
git status                       # clean
make manual                      # one last green build
```
