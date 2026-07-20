# UI Rework: design

Owner-directed: **full visual rework of both frontends** on shadcn/ui + Tailwind v4.
Theme comes from the IBM/Carbon `DESIGN.md` (fetched via `npx getdesign@latest add ibm`,
committed at the repo root as the design authority). The back office follows the
**Plate dashboard layout** (owner-supplied reference shot) wearing the Carbon skin.
The register gets a **purpose-built fast/legible language** in the same token family.
The old `frontend/web/DESIGN.md` (console-chrome) is **deleted — fully omitted**.

Owner's calls: both apps; light theme everywhere; Plate layout for the back office
**including a new "Today" landing**; register language optimized for speed and
readability.

## The design authority

- The IBM `DESIGN.md` lands at the **repo root** verbatim, followed by a short
  **POS appendix** section covering what a marketing extraction can't: 48px minimum
  touch targets on the register (its own responsive spec's floor; we build primary
  actions bigger), semantic color assignments (blue `#0f62fe` = the ONE accent —
  primary actions, links, focus, selected indicators; red `semantic-error` =
  destructive actions and their confirms; green `semantic-success` = paid/reconciled/
  on-track; yellow `semantic-warning` = variance/low-stock/long-stay), Carbon data-table
  conventions (hairline rows, `surface-1` stripes/hover, sentence-case headers), and
  the register's enlarged type/target scale.
- Non-negotiables inherited from the file's Do/Don't list: **0px corners everywhere**
  (`rounded.none` on every button, card, input, container; pills only for status
  badges), **no drop shadows** (hairlines + surface change carry depth), **weight-300
  display type** (never bold headlines), **sentence case** (no all-caps tracked
  labels — today's uppercase 11px labels die), `letter-spacing: 0.16px` on body,
  IBM Plex Sans everywhere.
- `frontend/web/DESIGN.md` deleted. Anything that referenced it (CLAUDE.md, docs)
  repointed to the root DESIGN.md.

## Toolchain

- **Tailwind v4** (CSS-first `@theme`) + **shadcn/ui** in BOTH apps, each with its own
  `components.json`, `src/lib/utils.ts` (`cn`), and `src/components/ui/*`.
- Tokens flow one way: DESIGN.md front-matter → CSS custom properties → Tailwind theme
  → component variants. One `tokens.css` per app generated from the same values (kept
  identical by review, not tooling — two files, one source of truth in DESIGN.md).
- **IBM Plex Sans self-hosted** via `@fontsource/ibm-plex-sans` (weights 300/400/600)
  — deterministic in Docker builds, no CDN, no `next/font/google` network dependency.
- shadcn primitives adopted and Carbon-ized: Button (five variants: primary blue /
  secondary charcoal / tertiary blue-outline / ghost / danger red — all radius-0,
  Carbon padding 12×16, pressed = `blue-80`), Input (surface-1 fill, bottom hairline,
  focus = 2px blue underline, error = 2px red underline), Select, Checkbox, Tabs
  (selected = 2px blue bottom border + weight-600), Table (Carbon data-table), Dialog +
  Sheet (square, hairline, no shadow — flat overlay scrim), Card (hairline tile),
  Badge (status pills — the one sanctioned pill shape).
- Old `src/index.css` + `src/styles/tokens.css` die in both apps. Bespoke CSS survives
  only where no primitive exists: receipt print styles (plain, functional — unchanged),
  and small POS-specific pieces (split strip, prep chips) rebuilt on the new tokens.
- No component library beyond shadcn's copied components; no animation library —
  transitions ≤100ms opacity/transform only.

## The frozen contract (load-bearing)

- **Every EXISTING user-visible label, flow, route, and behavior stays byte-identical.**
  The User Manual grep-verified ~250 strings; the wiki syncs from it; 160 component
  tests assert those labels and behaviors. Test changes are permitted ONLY where a test
  asserts styling internals (CSS class names, DOM-shape coupling like `nextSibling`);
  every label/role/behavior assertion passes UNCHANGED — that green suite is the proof
  the manual survives.
- **Named exceptions — exactly three, each with the Manager Guide updated in the SAME
  branch so the wiki never lies:**
  1. **The Today landing** (new screen, new labels).
  2. **The location switcher relocation**: Reports/Stock lose their per-screen location
     selects in favor of the sidebar switcher — a deliberate anatomy change to
     documented screens.
  3. **Archive/deactivate confirms move from `window.confirm` to the shadcn Dialog**
     with byte-identical copy — tests that mock `window.confirm` are behavior tests
     and get rewritten against the Dialog (asserting the SAME copy and the same
     cancel-blocks/confirm-proceeds semantics).
- Anything else that wants to change existing copy or documented anatomy stops and
  flags instead.

## Back office — Plate layout, Carbon skin

Reference anatomy adopted (owner's Plate shot), Carbon-skinned (blue not orange, 0px
not rounded, hairlines not soft shadows):

- **Left sidebar** (replaces the current top-bar shell): POS wordmark top; **location
  switcher** card beneath it (becomes THE location picker — the per-screen location
  selects on Reports/Stock collapse into it; screens read the sidebar's selected
  location); grouped nav with sentence-case eyebrows — **Operations**: Today, Catalog,
  Users, Locations & Registers; **Insights**: Reports, Audit — active item = surface-1
  tile + 2px blue left indicator; count badges only where real data exists (low-stock
  count on Today/Catalog); footer slot: the signed-in admin's name + Sign out.
- **Section headers**: weight-300 display-md title, context subline (`ink-muted`
  body-sm), right-aligned primary action as `button-primary` where a section has one.
- **"Today" landing (new; default section after sign-in):** composed ENTIRELY from
  existing endpoints — KPI card row (Net sales today, Orders closed, Refunds today —
  from `GET /admin/reports/sales?group_by=day` for today; Low stock count — from
  `GET /admin/reports/stock?low_only=true`), a **Needs attention** panel (low-stock
  rows; registers list state), and a recent-activity table (first page of
  `GET /admin/audit`). Zero new backend. Empty states designed (fresh day = zeros,
  not blanks).
- **Tables** (catalog lists, users, reports, audit): Carbon data-table — hairline row
  separators, `surface-1` hover and alternate stripes where dense, sentence-case
  headers in `ink-muted` body-sm, status as colored dot + label (green/yellow/red per
  the semantic map), row actions right-aligned as ghost buttons.
- **Editors** (catalog/users/places): Carbon form fields in hairline cards; ARCHIVED/
  INACTIVE badges become status pills; archive confirms move from `window.confirm` to
  the shadcn Dialog **with byte-identical copy** (the manual quotes it).
- Existing behaviors preserved exactly: PATCH-diff semantics, 401 conventions, token
  shown-once plate, CSV export, filter-then-LOAD-MORE audit pagination.

## Register — its own language: fast, legible, same DNA

Same tokens (Plex, blue, flat, hairlines), different dials — a till, not a dashboard:

- **Type scale up:** cart lines `body-lg` 18px; running total `display-md` 42px
  weight-300 tabular-nums; section titles `card-title` 24px. Money always tabular.
- **Touch scale up:** 48px is the FLOOR (per the design file); primary flows build
  bigger — menu tiles and floor tab tiles ≥96px tall, keypad-adjacent controls 56px.
- **The action zone:** each stage's single primary action lives in a **fixed
  full-width bottom bar** (64px, `button-primary` blue; destructive confirms
  `button-danger` red) — thumb-reachable, unmissable, one per screen (the old
  "warm = action" rule becomes "blue = action").
- **Two-pane sale screen:** cart pinned left (scrolling list, total always visible at
  the bottom of the pane), context pane right (scan-first idle state in retail mode /
  menu grid in food mode / tender + split strip during payment). No layout jumps
  between stages — panes swap content, chrome stays still.
- **Floor:** large hairline tiles (table ref big, due + age + server in `ink-muted`),
  status color as a left edge bar; NEW TAB as the bottom action-zone primary.
- **Modifier sheet:** full-height right Sheet, required groups stacked first,
  option rows 56px with the running delta pinned by the ADD action.
- **Feedback:** pressed states instant (surface/[blue-80] shifts, no ripple, no
  spinner under 300ms — optimistic UI already exists in the mutations; visual
  acknowledgment ≤100ms).
- Machinery untouched: stage machine, mounted-hidden screens, keyed mutations,
  If-Match discipline, blind-count masking, session conventions.

## Verification

- Per task: the app's full gates (`npm test && npm run typecheck && npm run build`)
  with label/role/behavior assertions passing UNCHANGED (styling-internal assertions
  may be updated, each one named in the task report); visual sanity against the
  running dev stack; DESIGN.md Do/Don't audit per screen (0px corners, one accent,
  no shadows, sentence case, weight-300 display).
- Manual truthfulness: chapters untouched except the Manager Guide's Today/nav
  updates; label audit re-run on touched manual sections.
- Final whole-branch review: Carbon-fidelity audit across every screen + the
  label-freeze proof + cross-app consistency (two apps, one language).
- Backend and e2e scripts untouched by construction (e2e asserts API responses, not
  markup).

## Out of scope

| Deferred | Revive when |
| --- | --- |
| Dark register theme (Carbon G100) | Glare complaints from a real till; tokens make it a palette swap. |
| Charts on Reports/Today | The tables prove insufficient; Carbon has chart specs. |
| Real KDS screen styling | The KDS feature itself (still deferred at product level). |
| Animation/motion polish beyond ≤100ms feedback | Never, probably — speed IS the brand here. |
