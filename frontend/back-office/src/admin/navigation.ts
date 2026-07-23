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
