# GitHub Pages site — design

**Date:** 2026-08-07
**Status:** Approved

## What

A public site at `https://kenprz.github.io/pos/` (Pages is already enabled, build type
"workflow"): a marketing-style landing page plus the full `docs/` set rendered as site
pages, all in the root `DESIGN.md` (IBM/Carbon) visual language. The wiki keeps
publishing from `docs/` as it does today; the site is a second, styled rendering of the
same sources.

## Why this shape

- **Full docs site** (user's call): landing + all 12 docs (7 design docs, 5 manual
  chapters), not just a landing page.
- **Bun script + `marked`** (user's call): a hand-designed landing and a small build
  script beat Jekyll (fighting a theme into Carbon) and VitePress/Starlight (a
  toolchain for 12 pages whose default look resists flat-square Carbon).

## Site structure

```
site/
  index.html        hand-written landing page (full HTML, no build step of its own)
  site.css          DESIGN.md tokens transcribed once — shared by landing and docs pages
  build.ts          Bun build script (marked) — renders docs/ into dist/
  build.test.ts     link-rewrite + fail-loudly tests
  template.html     the docs-page shell (nav, sidebar, content slot, footer)
.github/workflows/pages.yml
```

Build output (`site/dist/`, gitignored):

```
index.html                  copied landing
site.css
assets/…                    4–6 screenshots copied from docs/user-manual/assets/screenshots/
docs/index.html             rendered from docs/README.md
docs/00-overview.html … docs/06-roadmap.html
docs/manual-00-getting-started.html … docs/manual-04-operator-guide.html
```

All internal links are **relative** — the `/pos/` project base path then needs no
configuration anywhere.

## Landing page (index.html)

DESIGN.md's documented page rhythm, top to bottom:

1. **Utility bar** — 32px `#f4f4f4` ribbon; repo link, license, "Docs" shortcut.
2. **Top nav** — 48px white, 1px bottom hairline; wordmark "POS" left; links: Docs,
   Register, Back office, Quick start, GitHub.
3. **Hero** — display headline in Plex Sans weight 300 ("One order model. Two kinds of
   store."), `body-lg` subhead from the README's opening paragraph, primary CTA
   (Read the docs → `docs/`) + tertiary CTA (GitHub).
4. **Four surfaces** — 4-up hairline `feature-card` grid: Register, Back office,
   Desktop shell, API — copy condensed from the README table.
5. **Screenshots** — real PNGs from the user manual (register retail sale, menu
   grid/floor view, back-office Today, a report), flat frames, 1px hairline, captions
   in `caption` type.
6. **Principles band** — on `surface-1` `#f4f4f4`: the five README principles
   (integer cents, append-only, one order model, config vs data, server decides) as
   short hairline tiles.
7. **Quick start** — the four `make` commands in an inverse `#161616` code block,
   with the three localhost URLs.
8. **CTA banner** — full-bleed `#0f62fe`, headline type, "Read the design docs" →
   `docs/`.
9. **Footer** — charcoal `#161616`; columns: Docs, User manual, Project (GitHub,
   wiki, CI); `inverse-ink-muted` text.

## Docs pages

- Same utility bar, top nav, and footer as the landing.
- **Left sidebar**, two groups in reading order: *Design docs* (Overview →
  Roadmap), *User manual* (Getting started → Operator guide). Current page gets
  Carbon's selected treatment (2px `#0f62fe` left border, `body-emphasis` weight).
  Sidebar collapses to a `<details>` disclosure below 672px.
- **Content column** (~72ch max) typeset per DESIGN.md: headings on the documented
  scale, body 16px/1.50 with 0.16px tracking, tables with hairline rules and
  `#f4f4f4` header rows, code blocks on `#161616` with IBM Plex Mono, blockquotes
  with a 3px `#e0e0e0` left rule.
- `docs/index.html` renders `docs/README.md` and is the sidebar's "Docs home".

## Visual language (site.css)

Transcribed from DESIGN.md exactly once:

- Colors: `#0f62fe` primary (links, primary CTA, CTA banner, focus, selected-nav),
  `#161616` ink/inverse surfaces, `#525252`/`#8c8c8c` muted, `#ffffff` canvas,
  `#f4f4f4`/`#e0e0e0` surfaces/hairlines. No second accent.
- Type: IBM Plex Sans (300/400/600) + IBM Plex Mono, via Google Fonts. Display
  sizes at weight 300; body 16px with `letter-spacing: 0.16px`; the full documented
  scale as CSS variables.
- Geometry: `border-radius: 0` everywhere; hierarchy by 1px hairlines and surface
  change; **no shadows**; sentence-case eyebrows.
- Responsive per DESIGN.md's table: 4-up → 2-up at 1056px → 1-up below 672px; nav
  collapses below 672px; display type scales down preserving weight 300.
- No dark mode — DESIGN.md documents none; the footer is the only inverse surface.

## Build script (build.ts)

1. Clean `site/dist/`, copy `index.html`, `site.css`, and the listed screenshots.
2. For each of the 13 sources (12 docs + `docs/README.md`), render markdown with
   `marked` (GFM tables on), inject into `template.html` with title, sidebar (current
   page marked), and content.
3. **Link rewriting**, same contract as `scripts/wiki-sync.sh`: relative
   `NN-name.md` / `manual/NN-name.md` / `../NN-name.md` links (with optional
   `#anchor`) → the corresponding `.html`; `docs/README.md` links → `index.html`.
   A relative `.md` link that resolves to nothing in the page map **fails the
   build** — no silent 404s.
4. Heading anchors: `marked`'s GitHub-style slug ids, so existing `#anchor`
   cross-references keep working.

`site/package.json` carries the one dev dependency (`marked`). Tests in
`build.test.ts` cover: each rewrite shape, anchor preservation, and the unknown-link
failure.

## Deploy (pages.yml)

- Triggers: push to `main` touching `docs/**`, `site/**`, or the workflow itself;
  plus `workflow_dispatch`.
- Steps: checkout → `oven-sh/setup-bun` → `bun install` + `bun test` +
  `bun run site/build.ts` (in `site/`) → `actions/upload-pages-artifact` on
  `site/dist` → `actions/deploy-pages`. Standard `pages: write` / `id-token: write`
  permissions, `github-pages` environment.

## Out of scope

Search, dark mode, a screenshots-gallery page beyond the landing section, rendering
`docs/superpowers/` or `docs/user-manual/*.md` PDF sources, analytics, custom domain.

## Done when

`bun run site/build.ts` produces a dist that opens locally with every sidebar link,
cross-doc link, and anchor working; `bun test` passes in `site/`; a push to `main`
deploys it to `https://kenprz.github.io/pos/` via the new workflow.
