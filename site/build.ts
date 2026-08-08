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
