# POS user manual — design

**Date:** 2026-07-22
**Status:** Approved

## Goal

A printable, screenshot-rich end-user manual for the POS, produced by the pipeline
proven in `../artience-prs/docs/user-manual/`: markdown sources + real Playwright
screenshots of the running app, built into a committed PDF by python-markdown +
WeasyPrint, with a CI job that keeps the PDF current.

## Reference implementation

`../artience-prs/docs/user-manual/` (read at design time):

- `user-manual.md` — numbered chapters, each module in a fixed skeleton (overview →
  navigation → figure → field/action reference → statuses → business rules → common
  errors → best practices), figures as `![Figure N.M — caption](assets/screenshots/…)`.
- `troubleshooting.md`, `faq.md`, `glossary.md` — appended to the PDF in that order.
- `capture_screenshots.mjs` — Playwright; logs into the seeded app, captures a
  numbered page list into `assets/screenshots/`; re-runnable, overwrites by name.
- `build_pdf.py` — python-markdown (tables, toc, fenced_code, attr_list, sane_lists,
  md_in_html) → WeasyPrint → PDF; ```mermaid blocks rendered to hash-cached PNGs via
  `npx @mermaid-js/mermaid-cli` (skipped gracefully without Node); `manual.css` does
  A4, page numbers, page-break-per-h1.

We port this shape, not reinvent it.

## Deliverables — `docs/user-manual/`

| File | Content |
| --- | --- |
| `user-manual.md` | The manual (chapter outline below) |
| `troubleshooting.md` | Symptom → cause → fix tables (locked-out till, 401 after reissue, variance approval blocked from the closed register, printer/drawer, port collisions) |
| `faq.md` | Short Q&A (why is the register locked, why don't day and category reports reconcile, what's VAT-inclusive pricing, …) |
| `glossary.md` | Till, shift, float, Z-report, variance, tab, course, modifier, activation code, device token, VAT-inclusive, restock, … |
| `manual.css` | Ported from artience; accent color moved to the Carbon blue used by `DESIGN.md` |
| `capture_screenshots.mjs` | Playwright capture against the running dev stack (below) |
| `build_pdf.py` | Ported build script; chapter list = the four md files above |
| `assets/screenshots/*.png` | ~35 committed captures |
| `assets/diagrams/` | Mermaid render cache (committed) |
| `user-manual.pdf` | Committed build output |

`docs/manual/` (role guides, wiki-published) is untouched and remains accurate; the
new manual draws facts from it and from `docs/` but is written for print. The wiki
allowlist in `scripts/wiki-sync.sh` is not extended (no binary PDF on the wiki).

## Chapter outline (`user-manual.md`)

1. Introduction — audience, conventions, revision history table.
2. System overview — one order model, two surfaces (register / back office), roles
   and per-location RBAC, locations; mermaid: sale lifecycle, shift lifecycle.
3. Getting started — what you need; activating a terminal (activation code → device
   token, 7-day expiry, lockout screen on reissue); signing in (PIN at the till,
   email/password in the back office).
4. The register — layout in retail mode vs food mode; what mode is and who sets it.
5. Retail selling — scan/lookup, cart lines, quantity edits and weighed (per-kg)
   items, line/order discounts, voiding a line, cash payment and change, external
   card, receipts and reprints, refunds with restock.
6. Food service — the floor and tabs (`table_ref`), modifiers (required groups,
   repeat-legal add-ons, signed deltas), coursing and firing, qty bumps on fired
   lines, transfer between registers, splitting a tab, paying children.
7. Shifts and cash — opening with a float, cash movements (payout/deposit),
   Z-report before close, closing and counting, variance and supervisor approval
   (including the approve-from-another-register rule).
8. The back office — layout, Today landing, signing in.
9. Catalog — categories, products, variants (SKU/barcode/price), modifier groups,
   tax rates, discounts; archive-never-delete.
10. Users and roles — hiring, PINs, per-location role assignment, admin flag.
11. Locations and registers — settings, register mode, issuing/reissuing activation
    codes and what it kills.
12. Reports — sales by day/user (ledger basis) vs category (line basis) and why they
    don't reconcile; stock and low-stock.
13. Audit log — what gets recorded, filtering.
14. The desktop shell and printing — Tauri shell, thermal printer, cash drawer
    (mock driver status stated honestly).

Money is shown in pesos and screenshots show the Manila seed (GRC/RST/CAF) so prose
and figures agree.

## Screenshot capture

`capture_screenshots.mjs` requirements:

- Preconditions: `make dev` up, `POS_SEED_CATALOGS=grocery,restaurant,cafe make seed`
  freshly run. The script talks to register (5174), back office (5175), API (8000).
- Register flow is driven through the real UI: obtain an activation code (admin
  login → issue endpoint for a chosen till), type it into the activation screen, PIN
  in as seeded staff, open a shift, then stage and capture states — empty cart,
  scanned lines, discount applied, tender/change, receipt; switch to the food till
  for floor, tab with modifiers, coursing states, split screen; Z-report and close
  with a variance for the approval capture. ~20 register figures.
- Back office: email/password login, capture each section (Today, catalog list +
  product detail, modifiers, tax, discounts, users, locations, registers incl. the
  activation-code dialog, each report, audit). ~15 figures.
- Numbered filenames (`NNN-name.png`), overwrite-by-name, viewport fixed (1280×800)
  so re-captures diff cleanly. Seeder PINs/logins come from env or the script's
  documented constants — no secrets invented.
- Capture is local-only; CI never runs it.

## PDF build

- `build_pdf.py` ported: FILES = the four markdown files; same extensions; same
  mermaid hash-cache; `base_url` so relative asset paths resolve; pinned dependency
  versions documented in the script docstring (`pip install markdown==X
  weasyprint==Y pymdown-extensions==Z`).
- `manual.css` ported with Carbon-blue accents; A4, bottom-center page numbers,
  `h1` chapter breaks, table/figure break-avoidance.
- Makefile: `make manual-shots` (runs capture; requires the stack), `make manual`
  (builds the PDF; requires python deps, tolerates missing Node by leaving mermaid
  fences as code).

## CI

`.github/workflows/manual.yml`, following `wiki.yml`'s generated-artifact pattern:

- Trigger: push to the default branch touching `docs/user-manual/**` except
  `user-manual.pdf` and `assets/diagrams/**`.
- Steps: checkout → setup Python (pinned deps) + Node (for mermaid) → asset check
  (every `assets/…` path referenced by the markdown exists — fail loudly on a
  missing screenshot) → `python docs/user-manual/build_pdf.py` → commit the PDF
  (and refreshed diagram cache) back if changed.
- No byte-drift failure: PDF reproducibility across environments is not a goal;
  the pinned-version rebuild-and-commit keeps the committed PDF current instead.
- No screenshot capture in CI (needs a live seeded stack).

## Error handling

- `build_pdf.py` exits non-zero on missing markdown, unreadable assets referenced in
  prose (the CI asset check makes this loud pre-build), or WeasyPrint failure.
- Capture script fails fast per page with the page name in the error; partial output
  is safe (overwrite-by-name).

## Testing / acceptance

- `make manual` produces `user-manual.pdf` locally from committed sources.
- `make manual-shots` against a fresh all-catalog seed repopulates every referenced
  screenshot with no orphans (script ends by listing referenced-but-missing and
  captured-but-unreferenced files).
- CI workflow green on a docs-only push; PDF auto-commit observed once.
- Every figure referenced in the markdown exists; every chapter in the outline is
  present; facts spot-checked against `docs/manual/` and the shipped behavior
  (activation lockout, report bases, variance approval rule).
- Existing suites and wiki sync unaffected (no changes outside `docs/user-manual/`,
  `Makefile`, `.github/workflows/manual.yml`).

## Out of scope

- No changes to `docs/manual/` or the wiki allowlist.
- No localization (English only; peso amounts).
- No DOCX/HTML outputs (artience's docx variant is not ported).
- No screenshot automation in CI.
