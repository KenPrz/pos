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
