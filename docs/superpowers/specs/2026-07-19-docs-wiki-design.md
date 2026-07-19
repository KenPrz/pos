# Docs-to-Wiki: design

Owner-directed scope, arrived at by simplification: the original ask (laravel-export
static site + Typesense + DESIGN.md styling) collapsed, at the owner's direction, into
**a GitHub Wiki synced by CI** — rendered markdown, built-in search, zero hosting.
Two content libraries, Oracle-style: the existing **technical documentation** and a
**User Manual written fresh**.

Accepted trades (stated because the owner originally asked for them): no custom
styling (wikis render in GitHub's chrome — DESIGN.md does not apply), search is
GitHub's built-in wiki search (no Typesense).

**Done when:** the repo wiki shows the full User Manual + technical docs with a
working sidebar and landing page, and any `docs/**` edit on `main` updates the wiki
automatically.

## 1. The User Manual — `docs/manual/`

Written fresh, task-oriented, by role. Every claim sourced from shipped behavior
(register flows M3–M5, back office M6, operations M7) — no invented features, no
screenshots (text + exact UI labels). Files, in reading order:

| File | Chapter | Covers |
| --- | --- | --- |
| `00-getting-started.md` | Getting Started | What the system is; the three surfaces (register, back office, API); signing in: device enrollment (token paste), staff PINs, admin email login; roles in one page (cashier / supervisor / admin) |
| `01-cashier-guide.md` | Cashier's Guide | Clock in/out; open a shift (float); retail sale end-to-end (scan, cart, cash with change / card with reference, receipt, print); food mode: floor & tabs (new tab, resume, table refs), menu grid + modifier sheet (required groups, "double" repeats), courses (hold → fire → ready), split a check ×N and pay per-check; paying a transferred tab; close shift with the **blind count** (why expected cash is masked) |
| `02-supervisor-guide.md` | Supervisor's Guide | The supervisor override prompt; void line / void order (restock semantics); discounts (catalog discounts, order vs line, removal); refunds by receipt number (qty, restock choice); transfer a tab to another register (target must have an open shift); qty edits on fired lines; cash movements (paid in / payout / drop); the Z-report (incl. `orders_split` vs genuine voids); variance approval — from a DIFFERENT register than the one that just closed, and why |
| `03-manager-guide.md` | Manager's Guide (Back Office) | Signing in; catalog: categories, tax rates (percent shown, exact math server-side), products & variants (SKU/barcode/pricing, archive-never-delete and what archiving does to the register), modifier groups & attach order; staff: hiring (PIN + roles per location), promoting to back-office access, deactivating (history survives), the self-lockout guard; locations & registers: settings, register mode (grid vs scanner), device-token reissue as the lost-terminal kill switch; reports: sales by day/user (ledger basis) vs category (line basis) and why they don't reconcile, stock & low stock, CSV export; the audit trail: what's recorded, filtering, reading a payload |
| `04-operator-guide.md` | Operator's Guide | Requirements (Docker only); first run: `cp .env.example .env`, `make dev-key`, `make dev`, `make seed` (what the printed credentials are); the make target reference (`make help`); production: domains/DNS, `.env.prod.example`, `make prod-up`, TLS (automatic; `internal` issuer for smoke); backups: `make backup`, `make restore`, `make restore-drill` and why the drill exists; running the test suites and e2e proofs; troubleshooting: register says not enrolled (token rotation/reissue), drawer won't reconcile (Z, movements, variance flow), port conflicts, the `pos` project-name collision |

Tone: imperative, second person, one task per section, "what you see" before "what to
do" — Oracle user-guide conventions without the ceremony. Cross-references between
chapters use relative md links (the sync rewrites them).

## 2. The CI sync — `.github/workflows/wiki.yml`

- Trigger: `push` to `main` with `paths: [docs/**]` (plus `workflow_dispatch` for
  manual runs).
- Job: checkout → clone `https://x-access-token:${GITHUB_TOKEN}@github.com/<repo>.wiki.git`
  (needs `permissions: contents: write`) → build the wiki tree in a temp dir:
  - `docs/manual/*.md` → wiki pages named from chapter titles, hyphenated:
    `Getting-Started`, `Cashier-Guide`, `Supervisor-Guide`, `Manager-Guide`,
    `Operator-Guide`;
  - `docs/0*.md` + `docs/README.md` → `Home.md` (from README, links rewritten) and
    one page per numbered doc, named by topic: `Overview`, `Architecture`,
    `Data-Model`, `API`, `Backend-Conventions`, `RBAC`, `Roadmap` (no collisions with
    the manual names);
  - `docs/superpowers/**` NEVER syncs (internal working artifacts);
  - relative links between synced files rewritten to wiki page names; links out to
    unsynced repo files rewritten to absolute GitHub blob URLs;
  - `_Sidebar.md` generated: **User Manual** section (5 chapters in order), **Technical
    Documentation** section (the numbered docs in order);
  - `_Footer.md`: "Synced from `docs/` at <short-sha> — edit in the repo, not here."
- Sync = rsync-with-delete of the generated pages (manual wiki edits to synced pages
  are overwritten by design — the footer says so); commit only when `git status` is
  dirty; push.
- The transform lives in a committed script (`scripts/wiki-sync.sh`, bash + sed/awk —
  no new toolchain), invoked by CI and runnable locally for dry runs
  (`scripts/wiki-sync.sh /tmp/preview` builds the tree without pushing).

## 3. One-time manual step (owner)

GitHub exposes no API to create a wiki: the `.wiki.git` repo materializes only after
the first page is created in the UI. Before the first sync run: repo → Wiki tab →
Create the first page (any content — the sync overwrites it). The plan's final task
stops and asks for this at the right moment.

## Out of scope / deferred

| Deferred | Revive when |
| --- | --- |
| Styled static docs site (laravel-export + DESIGN.md chrome) + Typesense | The wiki's look or search proves insufficient — the manual's markdown is the asset either way; a renderer can be added without rewriting content. |
| Screenshots in the manual | First real deployment with stable UI to capture. |
| Versioned docs (per-release manuals) | First tagged release. |
