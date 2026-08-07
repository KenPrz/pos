# GitHub Pages Site Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A public site at `https://kenprz.github.io/pos/` — a Carbon-styled landing page plus all 12 `docs/` pages rendered to HTML — built by a small Bun script and deployed by a new Pages workflow.

**Architecture:** A `site/` directory holds a hand-written landing page, one CSS file transcribing the root `DESIGN.md` tokens, a docs-page template, and `build.ts` (Bun + `marked`) that renders `docs/README.md` + 12 docs into `site/dist/`, rewriting relative `.md` links to `.html` and failing loudly on unresolvable ones. `.github/workflows/pages.yml` builds and deploys `site/dist` on push to `main`.

**Tech Stack:** Bun, `marked` + `marked-gfm-heading-id` (only dependencies), GitHub Actions Pages deploy (`upload-pages-artifact` / `deploy-pages`).

**Spec:** `docs/superpowers/specs/2026-08-07-github-pages-site-design.md`

## Global Constraints

- Visual language is the root `DESIGN.md`, exactly: `border-radius: 0` everywhere; IBM Plex Sans with weight **300** for display sizes, 400/600 elsewhere; body `letter-spacing: 0.16px`; `#0f62fe` is the only accent; hierarchy by 1px `#e0e0e0` hairlines and `#f4f4f4` surface change; **no shadows**; sentence-case eyebrows; footer is the only inverse (`#161616`) surface besides code blocks.
- All internal links **relative** (site serves under `/pos/`); no base-path config anywhere.
- Bun, not Node (`bun install`, `bun test`, `bun run`) — per repo CLAUDE.md.
- An unresolvable relative `.md` link **fails the build** — never a silent 404.
- Repo URL: `https://github.com/KenPrz/pos`. Wiki URL: `https://github.com/KenPrz/pos/wiki`. No license claims (repo has no LICENSE file).
- Do not modify `CLAUDE.md` or `docs/06-roadmap.md` — both carry uncommitted user edits.

---

### Task 1: Scaffold `site/`

**Files:**
- Create: `site/package.json`
- Create: `site/bun.lock` (generated)
- Modify: `.gitignore` (append one line)

**Interfaces:**
- Produces: `site/` with `marked` + `marked-gfm-heading-id` installed; `site/dist/` gitignored.

- [ ] **Step 1: Create `site/package.json`**

```json
{
  "name": "pos-site",
  "private": true,
  "type": "module",
  "scripts": {
    "build": "bun run build.ts"
  }
}
```

- [ ] **Step 2: Install the two dependencies**

Run: `cd site && bun add -d marked marked-gfm-heading-id`
Expected: `bun.lock` created, `node_modules/` populated (already gitignored globally).

- [ ] **Step 3: Gitignore the build output**

Append to the root `.gitignore`, under the `# Node` block:

```
/site/dist/
```

- [ ] **Step 4: Commit**

```bash
git add site/package.json site/bun.lock .gitignore
git commit -m "chore(site): scaffold GitHub Pages site directory"
```

---

### Task 2: Build-script core (page map, link rewriting, sidebar) — TDD

**Files:**
- Create: `site/build.ts` (exports only in this task — the `import.meta.main` build body lands in Task 5)
- Test: `site/build.test.ts`

**Interfaces:**
- Produces: `PAGES: Page[]` (13 entries; `Page = { src, out, title, group }`), `SHOTS: string[]` (6 filenames), `rewriteLinks(md: string, srcDir: string): string` (throws on unresolvable `.md` link), `sidebar(current: string): string` (HTML string; current page gets `class="current" aria-current="page"`).

- [ ] **Step 1: Write the failing tests**

`site/build.test.ts`:

```ts
import { test, expect } from "bun:test";
import { rewriteLinks, sidebar, PAGES, SHOTS } from "./build";

test("bare filename link from docs/", () => {
  expect(rewriteLinks("see [x](02-data-model.md)", "docs"))
    .toBe("see [x](02-data-model.html)");
});

test("manual/ prefixed link from docs/", () => {
  expect(rewriteLinks("[x](manual/00-getting-started.md)", "docs"))
    .toBe("[x](manual-00-getting-started.html)");
});

test("bare filename link from docs/manual/", () => {
  expect(rewriteLinks("[x](01-cashier-guide.md)", "docs/manual"))
    .toBe("[x](manual-01-cashier-guide.html)");
});

test("../ link from docs/manual/", () => {
  expect(rewriteLinks("[x](../03-api.md)", "docs/manual"))
    .toBe("[x](03-api.html)");
});

test("anchor preserved", () => {
  expect(rewriteLinks("[x](01-cashier-guide.md#open-a-shift)", "docs/manual"))
    .toBe("[x](manual-01-cashier-guide.html#open-a-shift)");
});

test("absolute URLs untouched", () => {
  const md = "[x](https://example.com/page.md)";
  expect(rewriteLinks(md, "docs")).toBe(md);
});

test("unknown .md link fails the build", () => {
  expect(() => rewriteLinks("[x](nope.md)", "docs")).toThrow("unresolvable");
});

test("page map covers all 13 sources", () => {
  expect(PAGES.length).toBe(13);
  expect(PAGES[0].src).toBe("docs/README.md");
  expect(new Set(PAGES.map((p) => p.out)).size).toBe(13);
});

test("sidebar marks the current page", () => {
  const html = sidebar("03-api.html");
  expect(html).toContain('<a class="current" aria-current="page" href="03-api.html">API</a>');
  expect(html).toContain('<a href="00-overview.html">Overview</a>');
  expect(html).toContain("Design docs");
  expect(html).toContain("User manual");
});

test("six screenshots, all named", () => {
  expect(SHOTS.length).toBe(6);
  for (const s of SHOTS) expect(s).toMatch(/^\d{3}-[a-z-]+\.png$/);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd site && bun test`
Expected: FAIL — `build.ts` doesn't exist / exports missing.

- [ ] **Step 3: Write the core implementation**

`site/build.ts`:

```ts
// site/build.ts — renders docs/ into site/dist/ in the DESIGN.md language.
// Run: bun run build.ts   Test: bun test
import { marked } from "marked";
import { gfmHeadingId } from "marked-gfm-heading-id";
import { $ } from "bun";

export type Page = {
  src: string; // repo-relative markdown source
  out: string; // filename inside dist/docs/ (flat)
  title: string;
  group: "home" | "Design docs" | "User manual";
};

export const PAGES: Page[] = [
  { src: "docs/README.md", out: "index.html", title: "Docs home", group: "home" },
  { src: "docs/00-overview.md", out: "00-overview.html", title: "Overview", group: "Design docs" },
  { src: "docs/01-architecture.md", out: "01-architecture.html", title: "Architecture", group: "Design docs" },
  { src: "docs/02-data-model.md", out: "02-data-model.html", title: "Data model", group: "Design docs" },
  { src: "docs/03-api.md", out: "03-api.html", title: "API", group: "Design docs" },
  { src: "docs/04-backend-conventions.md", out: "04-backend-conventions.html", title: "Backend conventions", group: "Design docs" },
  { src: "docs/05-rbac.md", out: "05-rbac.html", title: "RBAC", group: "Design docs" },
  { src: "docs/06-roadmap.md", out: "06-roadmap.html", title: "Roadmap", group: "Design docs" },
  { src: "docs/manual/00-getting-started.md", out: "manual-00-getting-started.html", title: "Getting started", group: "User manual" },
  { src: "docs/manual/01-cashier-guide.md", out: "manual-01-cashier-guide.html", title: "Cashier guide", group: "User manual" },
  { src: "docs/manual/02-supervisor-guide.md", out: "manual-02-supervisor-guide.html", title: "Supervisor guide", group: "User manual" },
  { src: "docs/manual/03-manager-guide.md", out: "manual-03-manager-guide.html", title: "Manager guide", group: "User manual" },
  { src: "docs/manual/04-operator-guide.md", out: "manual-04-operator-guide.html", title: "Operator guide", group: "User manual" },
];

// Landing-page screenshots, copied from docs/user-manual/assets/screenshots/.
export const SHOTS = [
  "005-retail-cart.png",
  "008-retail-tender.png",
  "012-menu-grid.png",
  "011-floor.png",
  "022-bo-today.png",
  "031-bo-report-sales.png",
];

const BY_PATH = new Map(PAGES.map((p) => [p.src, p.out]));

function normalize(path: string): string {
  const parts: string[] = [];
  for (const seg of path.split("/")) {
    if (seg === "." || seg === "") continue;
    if (seg === "..") parts.pop();
    else parts.push(seg);
  }
  return parts.join("/");
}

// Rewrite relative `](x.md)` / `](x.md#anchor)` links to their rendered page.
// Same contract as scripts/wiki-sync.sh: an unresolvable .md link is a broken
// cross-reference — fail the build rather than ship a 404.
export function rewriteLinks(md: string, srcDir: string): string {
  return md.replace(/\]\(([^)\s]+\.md)(#[^)]*)?\)/g, (whole, rel, anchor = "") => {
    if (/^[a-z][a-z0-9+.-]*:/i.test(rel)) return whole; // absolute URL
    const out = BY_PATH.get(normalize(`${srcDir}/${rel}`));
    if (!out) throw new Error(`unresolvable .md link "${rel}" in ${srcDir}/`);
    return `](${out}${anchor})`;
  });
}

export function sidebar(current: string): string {
  const link = (p: Page) =>
    p.out === current
      ? `<a class="current" aria-current="page" href="${p.out}">${p.title}</a>`
      : `<a href="${p.out}">${p.title}</a>`;
  const group = (name: Page["group"]) =>
    `<p class="sidebar-group">${name}</p>` +
    PAGES.filter((p) => p.group === name).map(link).join("");
  return link(PAGES[0]) + group("Design docs") + group("User manual");
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd site && bun test`
Expected: 10 pass.

- [ ] **Step 5: Commit**

```bash
git add site/build.ts site/build.test.ts
git commit -m "feat(site): docs page map, .md link rewriting, sidebar"
```

---

### Task 3: `site/site.css` — DESIGN.md transcribed once

**Files:**
- Create: `site/site.css`

**Interfaces:**
- Produces: class names consumed by Tasks 4–5: `shell`, `utility-bar`, `spacer`, `top-nav`, `wordmark`, `button-primary`, `button-tertiary`, `button-on-blue`, `eyebrow`, `hero`, `actions`, `section` (+ `alt`), `card-grid`, `feature-card`, `shots`, `principles`, `principle`, `quickstart`, `cta-banner`, `footer`, `footer-cols`, `docs-layout`, `sidebar`, `sidebar-group`, `current`, `doc-content`.

- [ ] **Step 1: Write the stylesheet**

`site/site.css`:

```css
/* site/site.css — the root DESIGN.md (IBM/Carbon) tokens, transcribed once.
   0px corners everywhere, one blue, hairlines over shadows. */

:root {
  --primary: #0f62fe;
  --on-primary: #ffffff;
  --ink: #161616;
  --ink-muted: #525252;
  --ink-subtle: #8c8c8c;
  --canvas: #ffffff;
  --surface-1: #f4f4f4;
  --surface-2: #e0e0e0;
  --inverse-canvas: #161616;
  --inverse-surface-1: #262626;
  --inverse-ink: #ffffff;
  --inverse-ink-muted: #c6c6c6;
  --hairline: #e0e0e0;
  --blue-60: #0043ce;
  --blue-80: #002d9c;
  --blue-hover: #0050e6;
  --sans: "IBM Plex Sans", "Helvetica Neue", Arial, sans-serif;
  --mono: "IBM Plex Mono", ui-monospace, SFMono-Regular, monospace;
}

* { box-sizing: border-box; }

body {
  margin: 0;
  font-family: var(--sans);
  font-size: 16px;
  font-weight: 400;
  line-height: 1.5;
  letter-spacing: 0.16px;
  color: var(--ink);
  background: var(--canvas);
}

a { color: var(--primary); text-decoration: none; }
a:hover { color: var(--blue-60); text-decoration: underline; }
img { max-width: 100%; display: block; }

.shell { max-width: 1312px; margin: 0 auto; padding: 0 32px; }

/* ---- chrome ---- */

.utility-bar {
  background: var(--surface-1);
  color: var(--ink-muted);
  font-size: 12px;
  line-height: 1.33;
  letter-spacing: 0.32px;
}
.utility-bar .shell { height: 32px; display: flex; align-items: center; gap: 24px; }
.utility-bar a { color: var(--ink-muted); }
.spacer { margin-left: auto; }

.top-nav {
  background: var(--canvas);
  border-bottom: 1px solid var(--hairline);
  position: sticky;
  top: 0;
  z-index: 10;
}
.top-nav .shell { height: 48px; display: flex; align-items: center; gap: 32px; }
.wordmark { font-weight: 600; font-size: 16px; color: var(--ink); }
.wordmark:hover { color: var(--ink); text-decoration: none; }
.top-nav nav { display: flex; gap: 24px; margin-left: auto; }
.top-nav nav a { color: var(--ink); font-size: 14px; line-height: 1.29; }
.top-nav nav a:hover { color: var(--primary); text-decoration: none; }

/* ---- buttons (Carbon: square, 12px 16px, 14px labels) ---- */

.button-primary,
.button-tertiary,
.button-on-blue {
  display: inline-block;
  font-size: 14px;
  line-height: 1.29;
  letter-spacing: 0.16px;
  padding: 12px 16px;
  border-radius: 0;
  border: 1px solid transparent;
}
.button-primary { background: var(--primary); color: var(--on-primary); }
.button-primary:hover { background: var(--blue-hover); color: var(--on-primary); text-decoration: none; }
.button-primary:active { background: var(--blue-80); }
.button-tertiary { background: var(--canvas); color: var(--primary); border-color: var(--primary); }
.button-tertiary:hover { background: var(--surface-1); text-decoration: none; }
.button-on-blue { background: var(--canvas); color: var(--primary); }
.button-on-blue:hover { background: var(--surface-1); color: var(--blue-60); text-decoration: none; }

/* ---- landing sections ---- */

.eyebrow { font-size: 14px; line-height: 1.29; color: var(--ink-muted); margin: 0 0 12px; }

.hero { padding: 96px 0; }
.hero h1 {
  font-size: 60px;
  font-weight: 300;
  line-height: 1.17;
  letter-spacing: -0.4px;
  margin: 0 0 24px;
  max-width: 18ch;
}
.hero p { font-size: 18px; color: var(--ink-muted); max-width: 640px; margin: 0 0 32px; }
.actions { display: flex; gap: 16px; flex-wrap: wrap; }

.section { padding: 96px 0; }
.section.alt { background: var(--surface-1); }
.section h2 { font-size: 42px; font-weight: 300; line-height: 1.2; margin: 0 0 48px; }

.card-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; }
.feature-card { background: var(--canvas); border: 1px solid var(--hairline); padding: 24px; }
.feature-card h3 { font-size: 24px; font-weight: 400; line-height: 1.33; margin: 0 0 12px; }
.feature-card p { font-size: 14px; line-height: 1.5; color: var(--ink-muted); margin: 0; }

.shots { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
.shots figure { margin: 0; border: 1px solid var(--hairline); background: var(--canvas); }
.shots figcaption {
  font-size: 12px;
  line-height: 1.33;
  letter-spacing: 0.32px;
  color: var(--ink-muted);
  padding: 12px 16px;
  border-top: 1px solid var(--hairline);
}

.principles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
.principle { background: var(--canvas); border: 1px solid var(--hairline); padding: 24px; }
.principle h3 { font-size: 14px; font-weight: 600; line-height: 1.29; margin: 0 0 8px; }
.principle p { font-size: 14px; line-height: 1.5; color: var(--ink-muted); margin: 0; }

.quickstart pre {
  background: var(--inverse-canvas);
  color: var(--surface-1);
  font-family: var(--mono);
  font-size: 14px;
  line-height: 1.6;
  padding: 24px;
  margin: 0 0 24px;
  overflow-x: auto;
}
.quickstart .urls { font-size: 14px; color: var(--ink-muted); }
.quickstart .urls code { font-family: var(--mono); color: var(--ink); }

.cta-banner { background: var(--primary); color: var(--on-primary); padding: 48px 0; }
.cta-banner h2 { font-size: 32px; font-weight: 400; line-height: 1.25; margin: 0 0 8px; }
.cta-banner p { margin: 0 0 24px; color: var(--on-primary); }

/* ---- footer: the only inverse surface ---- */

.footer { background: var(--inverse-canvas); color: var(--inverse-ink-muted); padding: 64px 0; font-size: 14px; line-height: 1.29; }
.footer-cols { display: grid; grid-template-columns: repeat(4, 1fr); gap: 32px; }
.footer h3 { color: var(--inverse-ink); font-size: 14px; font-weight: 600; margin: 0 0 16px; }
.footer p { margin: 0; line-height: 1.5; }
.footer a { display: block; color: var(--inverse-ink-muted); padding: 4px 0; }
.footer a:hover { color: var(--inverse-ink); text-decoration: none; }

/* ---- docs pages ---- */

.docs-layout {
  display: grid;
  grid-template-columns: 256px minmax(0, 1fr);
  gap: 48px;
  padding-top: 48px;
  padding-bottom: 96px;
}

.sidebar { align-self: start; position: sticky; top: 80px; font-size: 14px; }
.sidebar summary { display: none; }
.sidebar-group {
  color: var(--ink-subtle);
  font-size: 12px;
  letter-spacing: 0.32px;
  margin: 24px 0 4px;
  padding-left: 14px;
}
.sidebar a {
  display: block;
  color: var(--ink-muted);
  padding: 6px 12px;
  border-left: 2px solid transparent;
}
.sidebar a:hover { color: var(--ink); background: var(--surface-1); text-decoration: none; }
.sidebar a.current { color: var(--ink); font-weight: 600; border-left-color: var(--primary); }

.doc-content { max-width: 72ch; min-width: 0; }
.doc-content h1 { font-size: 42px; font-weight: 300; line-height: 1.2; margin: 0 0 24px; }
.doc-content h2 { font-size: 32px; font-weight: 400; line-height: 1.25; margin: 48px 0 16px; }
.doc-content h3 { font-size: 24px; font-weight: 400; line-height: 1.33; margin: 32px 0 12px; }
.doc-content h4 { font-size: 20px; font-weight: 400; line-height: 1.4; margin: 24px 0 8px; }
.doc-content h5, .doc-content h6 { font-size: 14px; font-weight: 600; margin: 16px 0 8px; }
.doc-content p, .doc-content ul, .doc-content ol { margin: 0 0 16px; }
.doc-content li { margin: 4px 0; }
.doc-content table {
  border-collapse: collapse;
  font-size: 14px;
  line-height: 1.4;
  margin: 16px 0 24px;
  display: block;
  overflow-x: auto;
}
.doc-content th {
  background: var(--surface-1);
  text-align: left;
  font-weight: 600;
  padding: 12px 16px;
  border: 1px solid var(--hairline);
}
.doc-content td { padding: 12px 16px; border: 1px solid var(--hairline); vertical-align: top; }
.doc-content code {
  font-family: var(--mono);
  font-size: 0.875em;
  background: var(--surface-1);
  padding: 1px 5px;
}
.doc-content pre {
  background: var(--inverse-canvas);
  color: var(--surface-1);
  padding: 24px;
  margin: 16px 0 24px;
  overflow-x: auto;
  font-size: 14px;
  line-height: 1.6;
}
.doc-content pre code { background: none; color: inherit; padding: 0; font-size: inherit; }
.doc-content blockquote {
  margin: 16px 0;
  padding: 0 0 0 16px;
  border-left: 3px solid var(--surface-2);
  color: var(--ink-muted);
}
.doc-content hr { border: 0; border-top: 1px solid var(--hairline); margin: 48px 0; }

/* ---- responsive (DESIGN.md breakpoints: 1056 / 672) ---- */

@media (max-width: 1056px) {
  .card-grid { grid-template-columns: repeat(2, 1fr); }
  .shots { grid-template-columns: repeat(2, 1fr); }
  .principles { grid-template-columns: repeat(2, 1fr); }
  .footer-cols { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 672px) {
  .shell { padding: 0 16px; }
  .utility-bar { display: none; }
  .hero { padding: 48px 0; }
  .hero h1 { font-size: 36px; letter-spacing: 0; }
  .section { padding: 48px 0; }
  .section h2 { font-size: 28px; }
  .card-grid, .shots, .principles, .footer-cols { grid-template-columns: 1fr; }
  .docs-layout { grid-template-columns: 1fr; gap: 24px; }
  .sidebar { position: static; border: 1px solid var(--hairline); padding: 8px 12px 12px; }
  .sidebar summary { display: block; font-weight: 600; cursor: pointer; padding: 4px 0; }
  .doc-content h1 { font-size: 32px; }
  .doc-content h2 { font-size: 24px; }
}
```

- [ ] **Step 2: Commit**

```bash
git add site/site.css
git commit -m "feat(site): DESIGN.md token stylesheet for landing and docs pages"
```

---

### Task 4: Landing page

**Files:**
- Create: `site/index.html`

**Interfaces:**
- Consumes: `site.css` classes from Task 3; screenshot filenames = `SHOTS` from Task 2 (referenced as `assets/<name>`).
- Produces: the landing page copied verbatim to `dist/index.html` by Task 5.

- [ ] **Step 1: Write the landing page**

`site/index.html`:

```html
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>POS — a design-first point of sale</title>
<meta name="description" content="A point-of-sale system serving retail and food service from one order model. Laravel, PostgreSQL, React.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono&family=IBM+Plex+Sans:wght@300;400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="site.css">
</head>
<body>

<div class="utility-bar">
  <div class="shell">
    <span>Design-first · Laravel 13 · PostgreSQL 18 · React 19</span>
    <span class="spacer"></span>
    <a href="https://github.com/KenPrz/pos/wiki">Wiki</a>
    <a href="https://github.com/KenPrz/pos">GitHub</a>
  </div>
</div>

<header class="top-nav">
  <div class="shell">
    <a class="wordmark" href="index.html">POS</a>
    <nav>
      <a href="docs/index.html">Docs</a>
      <a href="#surfaces">Surfaces</a>
      <a href="#screens">Screens</a>
      <a href="#quickstart">Quick start</a>
      <a href="https://github.com/KenPrz/pos">GitHub</a>
    </nav>
  </div>
</header>

<section class="hero">
  <div class="shell">
    <p class="eyebrow">A point-of-sale system for one business, many locations</p>
    <h1>One order model. Two kinds of store.</h1>
    <p>Retail scans a barcode, pays, and leaves in a minute. A restaurant tab lingers
    for an hour, adds courses, and splits the check three ways. Same tables, same
    lifecycle, different screens.</p>
    <div class="actions">
      <a class="button-primary" href="docs/index.html">Read the docs</a>
      <a class="button-tertiary" href="https://github.com/KenPrz/pos">View on GitHub</a>
    </div>
  </div>
</section>

<section class="section alt" id="surfaces">
  <div class="shell">
    <p class="eyebrow">What's in the box</p>
    <h2>Four surfaces, one API, one database</h2>
    <div class="card-grid">
      <div class="feature-card">
        <h3>Register</h3>
        <p>The till. Scanner-first for retail; menu grid, floor view, open tabs,
        modifiers, coursing, and split checks for food service. Cash drawer with a
        blind count.</p>
      </div>
      <div class="feature-card">
        <h3>Back office</h3>
        <p>The manager's side: catalog, staff and per-location roles, register
        settings, sales and stock reports, and a viewer for the append-only audit
        trail.</p>
      </div>
      <div class="feature-card">
        <h3>Desktop shell</h3>
        <p>Tauri v2 hosts the same register and adds what a browser tab cannot do:
        a thermal printer and a cash drawer.</p>
      </div>
      <div class="feature-card">
        <h3>API</h3>
        <p>Laravel action-class architecture. Every mutation audited, every financial
        record append-only, all money in integer cents.</p>
      </div>
    </div>
  </div>
</section>

<section class="section" id="screens">
  <div class="shell">
    <p class="eyebrow">In the store</p>
    <h2>The register and the back office</h2>
    <div class="shots">
      <figure>
        <img src="assets/005-retail-cart.png" alt="Retail register with items scanned into the cart" loading="lazy">
        <figcaption>Retail — scan into the cart</figcaption>
      </figure>
      <figure>
        <img src="assets/008-retail-tender.png" alt="Cash tender screen showing computed change" loading="lazy">
        <figcaption>Cash tender with computed change</figcaption>
      </figure>
      <figure>
        <img src="assets/012-menu-grid.png" alt="Food-service menu grid with modifiers" loading="lazy">
        <figcaption>Food service — menu grid and modifiers</figcaption>
      </figure>
      <figure>
        <img src="assets/011-floor.png" alt="Floor view listing open tabs by table" loading="lazy">
        <figcaption>Floor view — open tabs by table</figcaption>
      </figure>
      <figure>
        <img src="assets/022-bo-today.png" alt="Back-office Today landing page" loading="lazy">
        <figcaption>Back office — the Today landing</figcaption>
      </figure>
      <figure>
        <img src="assets/031-bo-report-sales.png" alt="Sales report in the back office" loading="lazy">
        <figcaption>Sales reports, ledger-basis</figcaption>
      </figure>
    </div>
  </div>
</section>

<section class="section alt" id="principles">
  <div class="shell">
    <p class="eyebrow">Principles that shape everything</p>
    <h2>Decisions, written down first</h2>
    <div class="principles">
      <div class="principle">
        <h3>Money is integer cents, always</h3>
        <p>One rounding primitive in one place; split payments and refunds are
        penny-exact by construction.</p>
      </div>
      <div class="principle">
        <h3>Financial records are append-only</h3>
        <p>A refund is new rows; a closed order is never mutated; last year's receipt
        reprints identically.</p>
      </div>
      <div class="principle">
        <h3>One order model</h3>
        <p>Food service is retail plus a longer open phase — proven, not assumed: the
        food-service milestone shipped with zero new order tables.</p>
      </div>
      <div class="principle">
        <h3>Config is deployed, data is administered</h3>
        <p>Engineers change config; admins change the database; nothing lives in
        both.</p>
      </div>
      <div class="principle">
        <h3>The server decides, the terminal obeys</h3>
        <p>The API says what a receipt contains and whether a drawer may open. No
        money decision lives where it cannot be audited.</p>
      </div>
    </div>
  </div>
</section>

<section class="section quickstart" id="quickstart">
  <div class="shell">
    <p class="eyebrow">Quick start</p>
    <h2>Running in four commands</h2>
    <pre><code>cp .env.example .env
make dev-key          # mints an APP_KEY — paste it into .env
make dev              # full stack: Postgres, API, register, back office
make seed             # demo data — prints dev PINs and device tokens</code></pre>
    <p class="urls">Register at <code>localhost:5174</code> · back office at
    <code>localhost:5175</code> · API health at <code>localhost:8000/api/v1/health</code>.
    Requirements: Docker. Nothing else.</p>
  </div>
</section>

<section class="cta-banner">
  <div class="shell">
    <h2>The design is written down.</h2>
    <p>Seven design docs and a five-chapter user manual — the source of truth the
    code follows.</p>
    <a class="button-on-blue" href="docs/index.html">Read the design docs</a>
  </div>
</section>

<footer class="footer">
  <div class="shell footer-cols">
    <div>
      <h3>POS</h3>
      <p>A design-first point of sale for a single business across multiple
      locations — retail and food service from one order model.</p>
    </div>
    <div>
      <h3>Design docs</h3>
      <a href="docs/00-overview.html">Overview</a>
      <a href="docs/01-architecture.html">Architecture</a>
      <a href="docs/02-data-model.html">Data model</a>
      <a href="docs/03-api.html">API</a>
      <a href="docs/06-roadmap.html">Roadmap</a>
    </div>
    <div>
      <h3>User manual</h3>
      <a href="docs/manual-00-getting-started.html">Getting started</a>
      <a href="docs/manual-01-cashier-guide.html">Cashier guide</a>
      <a href="docs/manual-02-supervisor-guide.html">Supervisor guide</a>
      <a href="docs/manual-03-manager-guide.html">Manager guide</a>
      <a href="docs/manual-04-operator-guide.html">Operator guide</a>
    </div>
    <div>
      <h3>Project</h3>
      <a href="https://github.com/KenPrz/pos">GitHub</a>
      <a href="https://github.com/KenPrz/pos/wiki">Wiki</a>
      <a href="https://github.com/KenPrz/pos/actions">CI</a>
    </div>
  </div>
</footer>

</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add site/index.html
git commit -m "feat(site): landing page in the DESIGN.md language"
```

---

### Task 5: Docs template + build main; build and verify locally

**Files:**
- Create: `site/template.html`
- Modify: `site/build.ts` (append the `import.meta.main` build body)

**Interfaces:**
- Consumes: `PAGES`, `SHOTS`, `rewriteLinks`, `sidebar` (Task 2); `template.html` placeholders `{{title}}`, `{{sidebar}}`, `{{content}}`.
- Produces: `site/dist/` — the complete deployable site.

- [ ] **Step 1: Write the docs-page template**

`site/template.html` (all hrefs relative to `dist/docs/`):

```html
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{title}} · POS docs</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono&family=IBM+Plex+Sans:wght@300;400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../site.css">
</head>
<body>

<div class="utility-bar">
  <div class="shell">
    <span>Design-first · Laravel 13 · PostgreSQL 18 · React 19</span>
    <span class="spacer"></span>
    <a href="https://github.com/KenPrz/pos/wiki">Wiki</a>
    <a href="https://github.com/KenPrz/pos">GitHub</a>
  </div>
</div>

<header class="top-nav">
  <div class="shell">
    <a class="wordmark" href="../index.html">POS</a>
    <nav>
      <a href="index.html">Docs</a>
      <a href="https://github.com/KenPrz/pos">GitHub</a>
    </nav>
  </div>
</header>

<div class="shell docs-layout">
  <details class="sidebar" open>
    <summary>Docs</summary>
    {{sidebar}}
  </details>
  <main class="doc-content">
{{content}}
  </main>
</div>

<footer class="footer">
  <div class="shell footer-cols">
    <div>
      <h3>POS</h3>
      <p>A design-first point of sale for a single business across multiple
      locations — retail and food service from one order model.</p>
    </div>
    <div>
      <h3>Design docs</h3>
      <a href="00-overview.html">Overview</a>
      <a href="01-architecture.html">Architecture</a>
      <a href="02-data-model.html">Data model</a>
      <a href="03-api.html">API</a>
      <a href="06-roadmap.html">Roadmap</a>
    </div>
    <div>
      <h3>User manual</h3>
      <a href="manual-00-getting-started.html">Getting started</a>
      <a href="manual-01-cashier-guide.html">Cashier guide</a>
      <a href="manual-02-supervisor-guide.html">Supervisor guide</a>
      <a href="manual-03-manager-guide.html">Manager guide</a>
      <a href="manual-04-operator-guide.html">Operator guide</a>
    </div>
    <div>
      <h3>Project</h3>
      <a href="../index.html">Home</a>
      <a href="https://github.com/KenPrz/pos">GitHub</a>
      <a href="https://github.com/KenPrz/pos/wiki">Wiki</a>
    </div>
  </div>
</footer>

</body>
</html>
```

- [ ] **Step 2: Append the build body to `site/build.ts`**

```ts
if (import.meta.main) {
  const SITE = import.meta.dir;
  const ROOT = `${SITE}/..`;
  const DIST = `${SITE}/dist`;

  await $`rm -rf ${DIST}`;
  marked.use({ gfm: true });
  marked.use(gfmHeadingId());
  const template = await Bun.file(`${SITE}/template.html`).text();

  for (const p of PAGES) {
    const srcDir = p.src.slice(0, p.src.lastIndexOf("/"));
    const md = await Bun.file(`${ROOT}/${p.src}`).text();
    const body = await marked.parse(rewriteLinks(md, srcDir));
    // Function replacements: doc HTML can contain `$`-patterns String.replace treats specially.
    const page = template
      .replaceAll("{{title}}", p.title)
      .replace("{{sidebar}}", () => sidebar(p.out))
      .replace("{{content}}", () => body);
    await Bun.write(`${DIST}/docs/${p.out}`, page);
  }

  await Bun.write(`${DIST}/index.html`, Bun.file(`${SITE}/index.html`));
  await Bun.write(`${DIST}/site.css`, Bun.file(`${SITE}/site.css`));
  for (const shot of SHOTS) {
    await Bun.write(
      `${DIST}/assets/${shot}`,
      Bun.file(`${ROOT}/docs/user-manual/assets/screenshots/${shot}`),
    );
  }
  console.log(`built ${PAGES.length} doc pages + landing into site/dist/`);
}
```

- [ ] **Step 3: Run tests, then build**

Run: `cd site && bun test && bun run build.ts`
Expected: tests pass; "built 13 doc pages + landing into site/dist/".

- [ ] **Step 4: Verify no `.md` link leaked into the output**

Run: `grep -rEn 'href="[^"]*\.md["#]' site/dist/ && echo "LEAKED" || echo "clean"`
Expected: `clean`.

- [ ] **Step 5: Eyeball it**

Run: `cd site/dist && python3 -m http.server 8080` and open `http://127.0.0.1:8080/` — landing renders in the Carbon language, screenshots load, every sidebar link and a couple of cross-doc anchors (e.g. Manager guide → "End of day") work.

- [ ] **Step 6: Commit**

```bash
git add site/template.html site/build.ts
git commit -m "feat(site): docs template and full site build"
```

---

### Task 6: Pages deploy workflow

**Files:**
- Create: `.github/workflows/pages.yml`

**Interfaces:**
- Consumes: `site/` build from Task 5; Pages is already enabled on the repo with build type "workflow".

- [ ] **Step 1: Write the workflow**

`.github/workflows/pages.yml`:

```yaml
name: pages

on:
  push:
    branches: [main]
    paths:
      - "docs/**"
      - "site/**"
      - ".github/workflows/pages.yml"
  workflow_dispatch:

permissions:
  contents: read
  pages: write
  id-token: write

concurrency:
  group: pages
  cancel-in-progress: true

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: oven-sh/setup-bun@v2
      - run: bun install --frozen-lockfile
        working-directory: site
      - run: bun test
        working-directory: site
      - run: bun run build.ts
        working-directory: site
      - uses: actions/configure-pages@v5
      - uses: actions/upload-pages-artifact@v3
        with:
          path: site/dist

  deploy:
    needs: build
    runs-on: ubuntu-latest
    environment:
      name: github-pages
      url: ${{ steps.deployment.outputs.page_url }}
    steps:
      - id: deployment
        uses: actions/deploy-pages@v4
```

- [ ] **Step 2: Full local re-verify**

Run: `cd site && bun test && bun run build.ts`
Expected: all green — the workflow runs exactly these commands.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/pages.yml
git commit -m "ci: build and deploy the Pages site on push to main"
```

- [ ] **Step 4: Deploy**

Pushing to `main` publishes the site — **ask the user before pushing.** After push: watch `gh run watch` for the `pages` workflow, then check `https://kenprz.github.io/pos/`.
