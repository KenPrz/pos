# Back-Office URL Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Drive the back office's active section from the URL path (section registry + controlled Shell + real anchors), fix refresh/back/deep-link behavior, and persist the sidebar's location choice.

**Architecture:** One optional catch-all route feeds the existing single client app. A new `src/admin/navigation.ts` registry is the sole source of URL ↔ section ↔ permission truth; `AdminApp` syncs URL to state (`usePathname`/`useRouter`) with one stage-guarded normalization effect; `Shell` becomes a controlled component; `AppSidebar` items become real `<a href>` elements.

**Tech Stack:** Next.js 16 App Router (`next/navigation`), React 19, Vitest + Testing Library (jsdom).

**Spec:** `docs/superpowers/specs/2026-07-23-back-office-url-navigation-design.md`

## Global Constraints

- All work in `frontend/back-office/`; the register app is untouched.
- Today's canonical path is `/` — `/today` is NOT a route (normalizes away like any unknown slug).
- Slugs are the section keys: `/catalog`, `/users`, `/locations`, `/settings`, `/day`, `/reports`, `/audit`.
- The convention: **first path segment selects the section; everything after belongs to that section.** No section defines sub-routes yet, so non-empty rest is normalized to the section root.
- URL normalization (`router.replace`) runs ONLY while `stage.name === 'shell'` — the login screen must keep deep links intact.
- Sidebar nav items are plain `<a>`, not `next/link` (`Link` needs the app-router context, which jsdom tests don't have); unmodified left-click is hijacked to `onNavigate`, modified/middle clicks keep native behavior.
- Location persistence key: `pos.admin_location` (matches `pos.admin_token` / `pos.admin_user` naming). Not cleared on logout/401.
- Tests use `fireEvent`, `// @vitest-environment jsdom`, and the repo's existing mock idioms.
- Run test commands from `frontend/back-office/` via `./node_modules/.bin/vitest` (npx has resolved a stale cached vitest when cwd drifted — the local binary is immune).
- Commit messages carry no attribution trailers.

---

### Task 1: The section registry (`navigation.ts`)

**Files:**
- Create: `frontend/back-office/src/admin/navigation.ts`
- Test: `frontend/back-office/src/admin/navigation.test.ts` (new)

**Interfaces:**
- Produces (consumed by Tasks 2–3):
  - `SECTION_DEFS: Record<Section, { path: string; permissions: readonly string[] }>`
  - `type Section` (derived: `keyof typeof SECTION_DEFS`)
  - `parsePath(pathname: string): { section: Section | null; rest: string[] }`
  - `pathForSection(section: Section): string`
  - `holdsSection(section: Section, held: string[]): boolean`
  - `resolveSection(pathname: string, held: string[]): Section`

- [ ] **Step 1: Write the failing tests**

Create `src/admin/navigation.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { SECTION_DEFS, parsePath, pathForSection, resolveSection, type Section } from './navigation'

describe('parsePath', () => {
  it('maps / to today with no rest', () => {
    expect(parsePath('/')).toEqual({ section: 'today', rest: [] })
  })

  it('round-trips every registered section path', () => {
    for (const section of Object.keys(SECTION_DEFS) as Section[]) {
      expect(parsePath(pathForSection(section))).toEqual({ section, rest: [] })
    }
  })

  it('extracts rest segments belonging to the section', () => {
    expect(parsePath('/reports/stock')).toEqual({ section: 'reports', rest: ['stock'] })
    expect(parsePath('/catalog/products/123')).toEqual({ section: 'catalog', rest: ['products', '123'] })
  })

  it('tolerates trailing slashes', () => {
    expect(parsePath('/catalog/')).toEqual({ section: 'catalog', rest: [] })
  })

  it('returns null for unknown slugs, including /today', () => {
    expect(parsePath('/nope').section).toBeNull()
    expect(parsePath('/today').section).toBeNull()
  })
})

describe('resolveSection', () => {
  it('resolves a held section', () => {
    expect(resolveSection('/settings', ['settings.manage'])).toBe('settings')
  })

  it('falls back to today for an unheld section', () => {
    expect(resolveSection('/settings', ['catalog.manage'])).toBe('today')
  })

  it('falls back to today for unknown slugs', () => {
    expect(resolveSection('/nope', ['catalog.manage'])).toBe('today')
  })

  it('today needs no permission', () => {
    expect(resolveSection('/', [])).toBe('today')
  })

  it('grants a composite section on ANY of its permissions (OR-semantics)', () => {
    expect(resolveSection('/users', ['role.manage'])).toBe('users')
    expect(resolveSection('/reports', ['report.stock.view'])).toBe('reports')
    expect(resolveSection('/locations', ['register.enroll'])).toBe('locations')
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./node_modules/.bin/vitest run src/admin/navigation.test.ts`
Expected: FAIL — cannot resolve `./navigation`.

- [ ] **Step 3: Implement the registry**

Create `src/admin/navigation.ts`:

```ts
// Section registry — the ONE place URL ↔ section ↔ permission knowledge lives
// (spec: docs/superpowers/specs/2026-07-23-back-office-url-navigation-design.md).
// Convention: the first path segment selects the section; everything after it
// belongs to that section. Adding a page = one entry here + a sidebar item and
// render case in Shell. No section defines sub-routes yet, so AdminApp
// normalizes any non-empty `rest` away; a section that adopts sub-routes later
// opts in here (a validRest field) and takes over its own subtree.

export interface SectionDef {
  /** Canonical path — one segment; '/' is Today. */
  path: string
  /** OR-semantics: holding ANY listed permission shows the section. [] = always visible. */
  permissions: readonly string[]
}

export const SECTION_DEFS = {
  today: { path: '/', permissions: [] },
  catalog: { path: '/catalog', permissions: ['catalog.manage'] },
  users: { path: '/users', permissions: ['user.manage', 'role.manage'] },
  locations: { path: '/locations', permissions: ['location.manage', 'register.enroll'] },
  settings: { path: '/settings', permissions: ['settings.manage'] },
  day: { path: '/day', permissions: ['day.close'] },
  reports: { path: '/reports', permissions: ['report.sales.view', 'report.stock.view'] },
  audit: { path: '/audit', permissions: ['audit.view'] },
} as const satisfies Record<string, SectionDef>

export type Section = keyof typeof SECTION_DEFS

const BY_SLUG = new Map<string, Section>(
  (Object.keys(SECTION_DEFS) as Section[])
    .filter((s) => s !== 'today')
    .map((s) => [SECTION_DEFS[s].path.slice(1), s]),
)

export function parsePath(pathname: string): { section: Section | null; rest: string[] } {
  const segments = pathname.split('/').filter(Boolean)
  if (segments.length === 0) return { section: 'today', rest: [] }
  const section = BY_SLUG.get(segments[0]) ?? null
  return { section, rest: section ? segments.slice(1) : [] }
}

export function pathForSection(section: Section): string {
  return SECTION_DEFS[section].path
}

export function holdsSection(section: Section, held: string[]): boolean {
  const required = SECTION_DEFS[section].permissions
  return required.length === 0 || required.some((p) => held.includes(p))
}

/** Parse + permission-check in one step: unknown slug OR unheld section → 'today'. */
export function resolveSection(pathname: string, held: string[]): Section {
  const { section } = parsePath(pathname)
  if (section === null) return 'today'
  return holdsSection(section, held) ? section : 'today'
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./node_modules/.bin/vitest run src/admin/navigation.test.ts`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add src/admin/navigation.ts src/admin/navigation.test.ts
git commit -m "feat(back-office): section registry — URL/section/permission source of truth"
```

---

### Task 2: Controlled Shell + anchor-based sidebar

**Files:**
- Modify: `frontend/back-office/src/components/AppSidebar.tsx` (nav item `<button>` → `<a>`, item shape gains `href`)
- Modify: `frontend/back-office/src/admin/Shell.tsx` (drop `useState`, take `section`/`onNavigate` props, use the registry)
- Test: `frontend/back-office/src/admin/Shell.test.tsx`

**Interfaces:**
- Consumes: `SECTION_DEFS`, `holdsSection`, `pathForSection`, `type Section` from Task 1.
- Produces: `Shell` props gain `section: Section` and `onNavigate: (s: Section) => void`; `AppSidebarNavItem` gains `href: string`. Task 3 relies on exactly these.

- [ ] **Step 1: Update Shell.test.tsx to the controlled contract (failing first)**

Apply these changes to `src/admin/Shell.test.tsx`:

Replace the `renderShell` helper with one that passes the new props (`section` and an `onNavigate` spy) and returns the spy:

```tsx
function renderShell(
  stockRows: StockReportRow[] = [],
  onLogout = vi.fn(),
  sections: string[] = ALL_SECTIONS,
  section: Section = 'today',
) {
  vi.mocked(api.reports.sales).mockResolvedValue(EMPTY_SALES)
  vi.mocked(api.reports.stock).mockResolvedValue({ rows: stockRows })
  vi.mocked(api.registers.list).mockResolvedValue([])
  vi.mocked(api.audit.list).mockResolvedValue({ rows: [], page: 1, has_more: false })
  const onNavigate = vi.fn()
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <Shell
        user={{ id: 'u-1', name: 'Alex Admin', email: 'alex@pos.test', is_admin: true }}
        sections={sections}
        section={section}
        onNavigate={onNavigate}
        onLogout={onLogout}
        onUnauthorized={vi.fn()}
        location={LOCATION}
        locations={[LOCATION]}
        onLocationChange={vi.fn()}
      />
    </QueryClientProvider>,
  )
  return { onNavigate }
}
```

Add the import: `import type { Section } from './navigation'`.

Then update the tests — nav items are links now, and navigation is a callback:

- In every nav-item assertion, change `getByRole('button', { name: label })` / `queryByRole('button', …)` to `getByRole('link', …)` / `queryByRole('link', …)`. Affected tests: `'renders the five existing section labels…'`, `'defaults to Today…'`, `'shows no count badge…'`, `'badges Today…'`, `'shows only Today and Reports…'`, `'shows all seven nav items…'`, `'renders no nav item…'`. (`'fires onLogout…'` keeps `button` — Sign out is a real Button.)
- Replace the `'navigates to a section on click'` test with these two:

```tsx
  it('reports a nav click through onNavigate instead of navigating itself', () => {
    const { onNavigate } = renderShell()

    fireEvent.click(screen.getByRole('link', { name: 'Catalog' }))

    expect(onNavigate).toHaveBeenCalledExactlyOnceWith('catalog')
  })

  it('renders whichever section the prop names', () => {
    renderShell([], vi.fn(), ALL_SECTIONS, 'catalog')

    expect(screen.getByText('Catalog stub')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Catalog' })).toHaveAttribute('aria-current', 'page')
  })

  it('gives every nav item a real href for open-in-new-tab', () => {
    renderShell()

    expect(screen.getByRole('link', { name: 'Today' })).toHaveAttribute('href', '/')
    expect(screen.getByRole('link', { name: 'Reports' })).toHaveAttribute('href', '/reports')
  })
```

- [ ] **Step 2: Run to verify the suite fails**

Run: `./node_modules/.bin/vitest run src/admin/Shell.test.tsx`
Expected: FAIL — `Shell` doesn't accept `section`/`onNavigate` yet; nav items are still buttons.

- [ ] **Step 3: Convert AppSidebar items to anchors**

In `src/components/AppSidebar.tsx`, add `href` to the item shape:

```ts
export interface AppSidebarNavItem {
  key: string
  label: string
  href: string
  count?: number
}
```

Replace the `<button>` block (lines 83–97) with:

```tsx
                    <a
                      href={item.href}
                      aria-current={isActive ? 'page' : undefined}
                      onClick={(e) => {
                        // Hijack only unmodified left-clicks — modified/middle clicks
                        // keep native open-in-new-tab, which is why href is real.
                        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return
                        e.preventDefault()
                        onNavigate(item.key)
                      }}
                      className={cn(
                        'flex w-full items-center justify-between gap-xs border-l-2 border-transparent',
                        'px-xs py-sm text-left type-body-sm text-ink-muted hover:bg-surface-1',
                        isActive && 'border-primary bg-surface-1 font-semibold text-ink'
                      )}
                    >
                      <span>{item.label}</span>
                      {typeof item.count === 'number' ? (
                        <Badge variant="info">{item.count}</Badge>
                      ) : null}
                    </a>
```

- [ ] **Step 4: Make Shell controlled**

In `src/admin/Shell.tsx`:

1. Change the imports/type lines: delete `import { useState } from 'react'`; delete the local `export type Section = …` union and the `SECTION_RULES` const and `hasAny` helper; add:

```tsx
import { SECTION_DEFS, holdsSection, pathForSection, type Section } from './navigation'

export type { Section } from './navigation'
```

(Re-exporting keeps `import type { Section } from './Shell'` working anywhere it's used today.)

2. Add the two props and delete the state line:

```tsx
export function Shell({
  user,
  sections,
  section,
  onNavigate,
  onLogout,
  onUnauthorized,
  location,
  locations,
  onLocationChange,
}: {
  user: AdminUser | null
  sections: string[]
  // Controlled navigation (URL-driven): AdminApp resolves the pathname to a
  // permission-checked Section and passes it down; nav clicks go back up as
  // onNavigate → router.push. Shell never owns "where am I" anymore.
  section: Section
  onNavigate: (section: Section) => void
  onLogout: () => void
  onUnauthorized: () => void
  location: Location | null
  locations: Location[]
  onLocationChange: (id: string) => void
}) {
```

(delete `const [section, setSection] = useState<Section>('today')`)

3. Swap the permission derivations that used `hasAny`/`SECTION_RULES`:

```tsx
  const canManageCatalog = holdsSection('catalog', sections)
  const canManageUsers = sections.includes('user.manage')
  const canManageRoles = sections.includes('role.manage')
  const canManageLocations = holdsSection('locations', sections)
  const canViewSalesReport = sections.includes('report.sales.view')
  const canViewStockReport = sections.includes('report.stock.view')
  const canViewAudit = holdsSection('audit', sections)
  const canManageSettings = holdsSection('settings', sections)
  const canCloseDay = holdsSection('day', sections)
```

4. Give every nav item its href (the object literals in `navSections`), e.g.:

```tsx
        { key: 'today', label: 'Today', href: pathForSection('today'), count: lowStockCount > 0 ? lowStockCount : undefined },
        ...(canManageCatalog ? [{ key: 'catalog', label: 'Catalog', href: pathForSection('catalog') }] : []),
        ...(canManageUsers || canManageRoles ? [{ key: 'users', label: 'Users', href: pathForSection('users') }] : []),
        ...(canManageLocations ? [{ key: 'locations', label: 'Locations & Registers', href: pathForSection('locations') }] : []),
        ...(canManageSettings ? [{ key: 'settings', label: 'Settings', href: pathForSection('settings') }] : []),
        ...(canCloseDay ? [{ key: 'day', label: 'End of Day', href: pathForSection('day') }] : []),
```

and in the Insights group:

```tsx
        ...(canViewSalesReport || canViewStockReport ? [{ key: 'reports', label: 'Reports', href: pathForSection('reports') }] : []),
        ...(canViewAudit ? [{ key: 'audit', label: 'Audit', href: pathForSection('audit') }] : []),
```

(`SECTION_DEFS` is imported for the re-export/type derivation; if the linter flags it unused, drop it from the import list — `holdsSection`/`pathForSection`/`Section` are the ones Shell calls.)

- [ ] **Step 5: Run the Shell suite**

Run: `./node_modules/.bin/vitest run src/admin/Shell.test.tsx`
Expected: PASS (12 tests). Note: `AdminApp.tsx` does not compile against the new Shell props yet — that's Task 3; vitest only builds what the suite imports, so this suite is green while the app is mid-migration. Do NOT run typecheck at this step.

- [ ] **Step 6: Commit**

```bash
git add src/components/AppSidebar.tsx src/admin/Shell.tsx src/admin/Shell.test.tsx
git commit -m "feat(back-office): controlled Shell + anchor nav items"
```

---

### Task 3: URL wiring in AdminApp + catch-all route + location persistence

**Files:**
- Move: `frontend/back-office/app/page.tsx` → `frontend/back-office/app/[[...section]]/page.tsx`
- Modify: `frontend/back-office/src/admin/AdminApp.tsx`
- Test: `frontend/back-office/src/admin/AdminApp.test.tsx`

**Interfaces:**
- Consumes: `resolveSection`, `pathForSection` (Task 1); Shell's `section`/`onNavigate` props (Task 2).
- Produces: the running app — URL-driven sections, normalization, `pos.admin_location` persistence.

- [ ] **Step 1: Extend AdminApp.test.tsx (failing first)**

Apply to `src/admin/AdminApp.test.tsx`:

1. Add the router mock and a mutable pathname at the top (after the existing imports; `vi.mock` calls are hoisted, so the mutable variable must be declared with `var`-safe hoisting — use `let` at module scope, which the mock factory closes over):

```tsx
import { api } from '../lib/api'

let mockPathname = '/'
const routerMock = { push: vi.fn(), replace: vi.fn(), prefetch: vi.fn(), back: vi.fn(), forward: vi.fn() }
vi.mock('next/navigation', () => ({
  usePathname: () => mockPathname,
  useRouter: () => routerMock,
}))

vi.mock('../lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, locations: { ...actual.api.locations, list: vi.fn() } },
  }
})
```

2. In `beforeEach`, reset the mocks and give locations a safe default:

```tsx
beforeEach(() => {
  vi.stubGlobal('localStorage', fakeLocalStorage())
  mockPathname = '/'
  routerMock.push.mockClear()
  routerMock.replace.mockClear()
  vi.mocked(api.locations.list).mockResolvedValue([])
})
```

3. Add a session helper and location fixtures after the existing helpers:

```tsx
function storeSession(sections: string[] = ['catalog.manage', 'user.manage']) {
  adminToken.set('admin-token-abc')
  adminToken.setUser(
    { id: 'user-1', name: 'Alex Admin', email: 'alex@example.com', is_admin: false },
    sections,
    null,
  )
}

const LOC = (id: string, name: string, code: string) => ({
  id,
  name,
  code,
  timezone: 'Asia/Manila',
  prices_include_tax: true,
  receipt_header: null,
  receipt_footer: null,
  is_active: true,
  variance_approval_threshold_cents: null,
  low_stock_threshold: null,
})
```

4. Append the new tests inside the `describe`:

```tsx
  it('renders the section named by the URL', async () => {
    mockPathname = '/catalog'
    storeSession(['catalog.manage'])

    renderApp()

    await waitFor(() =>
      expect(screen.getByRole('link', { name: 'Catalog' })).toHaveAttribute('aria-current', 'page'),
    )
    expect(routerMock.replace).not.toHaveBeenCalled()
  })

  it('normalizes an unheld section URL to /', async () => {
    mockPathname = '/settings'
    storeSession(['catalog.manage'])

    renderApp()

    await waitFor(() => expect(routerMock.replace).toHaveBeenCalledWith('/'))
    expect(screen.getByRole('link', { name: 'Today' })).toHaveAttribute('aria-current', 'page')
  })

  it('normalizes unknown slugs to /', async () => {
    mockPathname = '/nope'
    storeSession()

    renderApp()

    await waitFor(() => expect(routerMock.replace).toHaveBeenCalledWith('/'))
  })

  it('flattens sub-paths to the section root until sections define sub-routes', async () => {
    mockPathname = '/reports/stock'
    storeSession(['report.sales.view'])

    renderApp()

    await waitFor(() => expect(routerMock.replace).toHaveBeenCalledWith('/reports'))
  })

  it('leaves a deep link untouched on the login screen', () => {
    mockPathname = '/reports'

    renderApp()

    expect(screen.getByLabelText(/email/i)).toBeInTheDocument()
    expect(routerMock.replace).not.toHaveBeenCalled()
    expect(routerMock.push).not.toHaveBeenCalled()
  })

  it('pushes the section path when the sidebar navigates', async () => {
    storeSession(['catalog.manage'])

    renderApp()

    const link = await screen.findByRole('link', { name: 'Catalog' })
    fireEvent.click(link)

    expect(routerMock.push).toHaveBeenCalledWith('/catalog')
  })

  it('restores the stored location choice when it is still visible', async () => {
    vi.mocked(api.locations.list).mockResolvedValue([LOC('loc-a', 'Alpha', 'A'), LOC('loc-b', 'Beta', 'B')])
    localStorage.setItem('pos.admin_location', 'loc-b')
    storeSession()

    renderApp()

    await waitFor(() => expect(screen.getByRole('combobox', { name: 'Location' })).toHaveTextContent('Beta · B'))
  })

  it('falls back to the first visible location when the stored one is gone', async () => {
    vi.mocked(api.locations.list).mockResolvedValue([LOC('loc-a', 'Alpha', 'A')])
    localStorage.setItem('pos.admin_location', 'loc-gone')
    storeSession()

    renderApp()

    await waitFor(() => expect(screen.getByRole('combobox', { name: 'Location' })).toHaveTextContent('Alpha · A'))
  })
```

Add `fireEvent` to the testing-library import.

- [ ] **Step 2: Run to verify the new tests fail**

Run: `./node_modules/.bin/vitest run src/admin/AdminApp.test.tsx`
Expected: the 8 new tests FAIL (AdminApp doesn't read the URL and Shell isn't handed the new props — this file won't even compile against Task 2's Shell until Step 3, which is the point); the 3 existing tests may fail for the same compile reason. Proceed.

- [ ] **Step 3: Move the route file**

```bash
mkdir -p 'app/[[...section]]'
git mv app/page.tsx 'app/[[...section]]/page.tsx'
```

The file content is unchanged — it just renders `<AdminApp />`; the optional catch-all makes `/`, every slug, and any deeper path resolve to it.

- [ ] **Step 4: Wire AdminApp**

In `src/admin/AdminApp.tsx`:

1. Add imports:

```tsx
import { usePathname, useRouter } from 'next/navigation'
import { pathForSection, resolveSection } from './navigation'
```

2. Inside the component, after the existing state declarations, add:

```tsx
  const pathname = usePathname()
  const router = useRouter()
```

3. After the `visibleLocations` memo, replace the default-location effect with the restore-aware version, and add the persistence callback + the normalization effect:

```tsx
  // Prefer the persisted location (a UI preference — survives logout and 401)
  // when it's still visible to this session; otherwise first visible, as before.
  useEffect(() => {
    if (!locationId && visibleLocations.length > 0) {
      const stored = localStorage.getItem(LOCATION_KEY)
      const restored = stored !== null && visibleLocations.some((l) => l.id === stored)
      setLocationId(restored ? stored : visibleLocations[0].id)
    }
  }, [visibleLocations, locationId])

  const changeLocation = useCallback((id: string) => {
    localStorage.setItem(LOCATION_KEY, id)
    setLocationId(id)
  }, [])

  // URL → section, permission-checked; URL honesty: while the shell is up, a
  // pathname that resolves to something other than itself (unknown slug, unheld
  // section, sub-path no section owns yet) is replaced with the canonical path.
  // Stage-guarded: during login `sections` is [] and this would eat deep links.
  const section = resolveSection(pathname, sections)
  useEffect(() => {
    if (stage.name !== 'shell') return
    const canonical = pathForSection(resolveSection(pathname, sections))
    if (pathname !== canonical) router.replace(canonical)
  }, [stage.name, pathname, sections, router])
```

with the key at module scope (above the component, next to the `Stage` type):

```tsx
const LOCATION_KEY = 'pos.admin_location'
```

4. Update the `Shell` render to pass the new props and the persisting callback:

```tsx
  return (
    <Shell
      user={user}
      sections={sections}
      section={section}
      onNavigate={(s) => router.push(pathForSection(s))}
      onLogout={logout}
      onUnauthorized={handleUnauthorized}
      location={selectedLocation}
      locations={visibleLocations}
      onLocationChange={changeLocation}
    />
  )
```

- [ ] **Step 5: Run the AdminApp suite**

Run: `./node_modules/.bin/vitest run src/admin/AdminApp.test.tsx`
Expected: PASS (11 tests: 3 existing + 8 new). If `getByRole('combobox', { name: 'Location' })` misses, the Radix SelectTrigger carries the `aria-label="Location"` (`AppSidebar.tsx:59`) — check the role jsdom assigns with `screen.debug()` and adjust role, not the assertion's intent.

- [ ] **Step 6: Commit**

```bash
git add 'app/[[...section]]' src/admin/AdminApp.tsx src/admin/AdminApp.test.tsx
git commit -m "feat(back-office): URL-driven sections + location persistence"
```

---

### Task 4: Full gate and PR

**Files:** none — verification only.

- [ ] **Step 1: Full suite**

Run: `npm test`
Expected: PASS, ≥ 208 tests (193 current + ~15 new), 0 failures.

- [ ] **Step 2: Typecheck, lint, build**

Run: `npm run typecheck && npm run lint && npm run build`
Expected: tsgo 0 errors (pre-existing fast-refresh warnings only); oxlint 0 errors; build succeeds and the route table shows the catch-all (`/[[...section]]`) instead of `/`.

- [ ] **Step 3: Live check** (skip if the dev stack's back-office container isn't running)

With `make dev` up: visit `http://127.0.0.1:5175/reports` logged out → login card → after login you land on Reports; refresh stays on Reports; Back returns to previously visited sections; middle-click a nav item opens a new tab; `http://127.0.0.1:5175/nope` lands on Today with the URL rewritten to `/`; switch location, refresh, and the choice survives.

- [ ] **Step 4: Push and open the PR**

```bash
git push -u origin bo-url-navigation
gh pr create --base main --title "Back office: URL-driven navigation (section registry, deep links, location persistence)" --body "$(cat <<'EOF'
Sections are now driven by the URL path, per the spec in `docs/superpowers/specs/2026-07-23-back-office-url-navigation-design.md`. Refresh keeps your place, Back/Forward work, sections can be bookmarked/shared/middle-clicked, and a deep link survives the login screen.

## How

- **Section registry** (`src/admin/navigation.ts`): one table owns URL ↔ section ↔ permission; the `Section` type derives from it. Convention: the first path segment selects the section, everything after belongs to that section — nested routes later are a per-section opt-in, not a migration. Today lives at `/`; slugs match section keys (`/catalog` … `/audit`).
- **Controlled Shell**: `section`/`onNavigate` are props; the `useState` navigation is gone. `resolveSection` permission-checks upstream, so Shell can never be handed a section the session doesn't hold.
- **URL honesty**: unknown slug, unheld section, or un-owned sub-path is `router.replace`d to the canonical path — the URL never names a screen that isn't showing. Normalization only runs in the shell stage, so a logged-out deep link is preserved through login.
- **Real anchors** in the sidebar (plain `<a href>`, left-click hijacked to SPA nav) — middle/Ctrl-click opens sections in a new tab.
- **Location persistence**: the sidebar's location choice is stored under `pos.admin_location` and restored when still visible to the session.

The API's permission enforcement is unchanged — URL gating remains a UX concern; the server still 403s.

## Tests

Back-office suite 193 → 208+: the registry exhaustively unit-tested, Shell suite reworked to the controlled contract (simpler — no router involved), AdminApp suite covers URL→section wiring, all three normalization cases, deep-link-through-login, push-on-navigate, and location restore/fallback. Typecheck/lint/build green.
EOF
)"
```
