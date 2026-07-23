# Back-office URL-driven navigation

**Date:** 2026-07-23
**Status:** Approved

## Problem

The back office is a single Next.js page whose navigation lives entirely in React
state (`Shell.tsx`: `useState<Section>('today')`). The URL never changes, so:

- Refresh always lands on Today (so does re-login after a 401).
- Browser Back exits the app instead of returning to the previous section.
- No section can be deep-linked, bookmarked, or shared; nav items can't be
  middle-clicked into a new tab.
- The sidebar's location choice is also lost on refresh (separate state, same
  symptom).

## Decision

Drive the section from the URL path. One optional catch-all route feeds the
existing client app; a **section registry** in `src/admin/navigation.ts` becomes
the single source of truth for section identity, URL, and required permissions;
`Shell` becomes a controlled component. Today lives at `/` (there is no
`/today`); the other seven sections use their section keys as slugs: `/catalog`,
`/users`, `/locations`, `/settings`, `/day`, `/reports`, `/audit`.

Approaches rejected: query param (`?section=`) — works but uglier URLs and no
real anchors; full nested App Router routes (segment per section, auth in
layouts) — forces the deliberate single-client-boundary architecture apart and
rewrites the auth flow for code-splitting benefits that don't matter at this
size.

## The convention (reference for future development)

**Rule: the first path segment selects the section; everything after it belongs
to that section.**

### The registry

```ts
// src/admin/navigation.ts — the ONE place URL ↔ section ↔ permission knowledge lives
const SECTION_DEFS = {
  today:     { path: '/',          permissions: [] },   // [] = always visible
  catalog:   { path: '/catalog',   permissions: ['catalog.manage'] },
  users:     { path: '/users',     permissions: ['user.manage', 'role.manage'] },
  locations: { path: '/locations', permissions: ['location.manage', 'register.enroll'] },
  settings:  { path: '/settings',  permissions: ['settings.manage'] },
  day:       { path: '/day',       permissions: ['day.close'] },
  reports:   { path: '/reports',   permissions: ['report.sales.view', 'report.stock.view'] },
  audit:     { path: '/audit',     permissions: ['audit.view'] },
} as const satisfies Record<string, SectionDef>

export type Section = keyof typeof SECTION_DEFS   // derived, never hand-written
```

`permissions` is OR-semantics (hold any one) — the same rule `Shell`'s old
`SECTION_RULES` encoded; that constant moves here and `Shell` imports the
registry instead.

### Functions (all pure)

- `parsePath(pathname): { section: Section | null, rest: string[] }` — splits on
  `/`, drops empty segments, looks up the first against the registry.
  `'/' → { section: 'today', rest: [] }`; `'/reports/stock' → { section:
  'reports', rest: ['stock'] }`; `'/nope' → { section: null, rest: [] }`.
- `pathForSection(section): string` — the canonical path (`'today' → '/'`).
- `resolveSection(pathname, heldPermissions): Section` — parse, then permission
  check; unknown slug **or** unheld permission resolves to `'today'`.

### How to add a page (future)

1. Add one entry to `SECTION_DEFS` (key, path, permissions).
2. Add the sidebar item and the render case in `Shell.tsx`.

Nothing else: the `Section` type, URL parsing, permission gating, and
normalization all derive from the registry.

### How to add nested routes (future)

The seams are already in place; turning on nesting is per-section and additive:

1. The optional catch-all route (`app/[[...section]]/page.tsx`) already matches
   any depth — no route-file change.
2. `parsePath` already returns `rest` — the sub-segments belong to the section.
3. Give the section's registry entry a rest-validation field (e.g.
   `validRest?: (rest: string[]) => boolean`) and teach the normalization step
   to consult it instead of flattening; pass `rest` into that section component
   as a prop, where it drives the tab/entity shown.

Until a section opts in, **any non-empty `rest` is normalized away**
(`/reports/stock` → `router.replace('/reports')`): the URL must never name a
screen the app isn't actually showing.

## Component changes

**`app/page.tsx` → `app/[[...section]]/page.tsx`** — same three lines, renders
`<AdminApp />`. The optional catch-all makes `/`, `/reports`, and any deeper
path resolve to the one client app instead of 404ing.

**`AdminApp.tsx`** — owns the URL↔state sync:

- `usePathname()` + `useRouter()` from `next/navigation`.
- `section = resolveSection(pathname, sections)` passed to `Shell`, with
  `onNavigate = (s) => router.push(pathForSection(s))`.
- One normalization effect: **only while `stage === 'shell'`**, if `pathname !==
  pathForSection(section)`, `router.replace(pathForSection(section))`. This
  single rule covers unknown slugs (`/nope` → `/`), unheld sections
  (`/settings` without `settings.manage` → `/`), and flattened rest
  (`/reports/stock` → `/reports`). The stage guard is load-bearing: during
  `login`, `sections` is `[]` and normalization would strip a deep link before
  the user could sign in.
- Deep-link-through-login works with no extra code: the login stage never
  touches the router, so `/reports` survives login and resolves once the shell
  mounts with real permissions.
- **Location persistence:** write `locationId` to `localStorage` under
  `pos.admin_location` (matching the `pos.admin_token`/`pos.admin_user` naming)
  on every change; when the visible-locations list arrives, prefer the stored id
  if it's in the list, else fall back to the first visible location. Cleared by
  neither logout nor 401 — it's a UI preference, not a credential.

**`Shell.tsx`** — controlled: `section: Section` and `onNavigate: (s: Section)
=> void` become props; the `useState` on line 63 is deleted; `SECTION_RULES`
moves out to the registry. The render switch and all eight section components
are unchanged. Because `resolveSection` guards permissions upstream, `Shell`
can never receive a section the session doesn't hold.

**`AppSidebar.tsx`** — nav items render as real `<a href={item.href}>` (the
item shape gains `href: string`), styled exactly as the buttons are today, with
`aria-current="page"` kept. The click handler calls `event.preventDefault()`
and `onNavigate(item.key)` **only for unmodified left-clicks**; middle-click,
Ctrl/Cmd-click, and Shift-click fall through to the browser for
open-in-new-tab. Deliberately `<a>`, not `next/link`: `Link` requires the Next
app-router context, which the jsdom component tests don't have, and client-side
`router.push` via `onNavigate` gives the same SPA navigation.

## Out of scope

Tab-level URLs (`/reports/stock`), filter/entity deep links, any register-app
change, server-side auth/middleware (the API already enforces permissions with
403s; URL gating remains a UX concern).

## Error handling

- Unknown slug, unheld section, unexpected depth: rendered as Today (or the
  section root) with `router.replace` so the URL is honest and Back never
  returns to a dead URL.
- `/today` is deliberately not a route (Today's canonical path is `/`) — it
  normalizes to `/` like any unknown slug.
- Stored location id no longer visible (revoked permission, archived location):
  silently falls back to first visible, storage overwritten on next change.

## Testing

- `navigation.test.ts` — exhaustive unit tests: every registry slug round-trips
  (`parsePath`/`pathForSection`), unknown slugs, rest extraction, permission
  fallbacks (held/unheld/empty, OR-semantics).
- `Shell.test.tsx` — updated for controlled props (pass `section`, spy
  `onNavigate`) and nav items being links (`getByRole('link')`); gating tests
  unchanged in spirit.
- `AdminApp.test.tsx` — `next/navigation` mocked (mutable pathname + push/replace
  spies): URL→section wiring, `replace('/')` for unauthorized and unknown
  paths, `replace('/reports')` for `/reports/stock`, deep link surviving login
  stage (no replace while logged out), location restore-from-storage.
- Gate: `npm test`, `npm run typecheck`, `npm run lint`, `npm run build` in
  `frontend/back-office`.
