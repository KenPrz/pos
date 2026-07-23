'use client'

import { useQuery } from '@tanstack/react-query'
import { AppSidebar, type AppSidebarNavSection } from '../components/AppSidebar'
import { api, type AdminUser, type Location } from '../lib/api'
import { holdsSection, pathForSection, type Section } from './navigation'
import { AuditSection } from './audit/AuditSection'
import { CatalogSection } from './catalog/CatalogSection'
import { EndOfDaySection } from './day/EndOfDaySection'
import { PlacesSection } from './places/PlacesSection'
import { ReportsSection } from './reports/ReportsSection'
import { SettingsSection } from './settings/SettingsSection'
import { TodaySection } from './today/TodaySection'
import { UsersSection } from './users/UsersSection'

// The section-permission mapping now lives in the registry (./navigation.ts —
// SECTION_DEFS), which also owns each section's URL. `today` needs no permission.
// The two composite sections (Users, Reports) still read their permissions
// individually below to decide which *tab* to show inside the section.
export type { Section } from './navigation'

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
  // The canonical section-permission set for this session (RBAC v2 Task 6/11) — an
  // admin's is the full catalog; anyone else's is whatever `AdminAccess::sectionsFor`
  // computed from their roles/direct grants. Gates every nav item except Today.
  sections: string[]
  // Controlled navigation (URL-driven): AdminApp resolves the pathname to a
  // permission-checked Section and passes it down; nav clicks go back up as
  // onNavigate → router.push. Shell never owns "where am I" anymore.
  section: Section
  onNavigate: (section: Section) => void
  onLogout: () => void
  // Threaded down to every section's queries/mutations: any 401 anywhere in the shell
  // drops back to the login screen the same way the register's onSessionExpired does.
  onUnauthorized: () => void
  // The sidebar's location switcher is THE location picker (AdminApp owns the state;
  // Today and Reports/Stock consume it as a prop — the per-screen pickers are gone).
  location: Location | null
  locations: Location[]
  onLocationChange: (id: string) => void
}) {
  const canManageCatalog = holdsSection('catalog', sections)
  const canManageUsers = sections.includes('user.manage')
  const canManageRoles = sections.includes('role.manage')
  const canManageLocations = holdsSection('locations', sections)
  const canViewSalesReport = sections.includes('report.sales.view')
  const canViewStockReport = sections.includes('report.stock.view')
  const canViewAudit = holdsSection('audit', sections)
  const canManageSettings = holdsSection('settings', sections)
  const canCloseDay = holdsSection('day', sections)

  // Same query, same key, `TodaySection` itself uses for its low-stock KPI — React
  // Query dedupes this against TodaySection's request rather than firing a second one,
  // so the nav's low-stock count costs nothing extra over rendering Today itself. Runs
  // regardless of which section is active, so the badge stays honest even while parked
  // on Catalog or Reports. Gated on `report.stock.view` the same way TodaySection gates
  // its own stock widget — a session without it never fires this request either, so the
  // badge is silently absent rather than 403ing in the background.
  const stockQuery = useQuery({
    queryKey: ['admin', 'today', 'stock', location?.id ?? null],
    queryFn: () => api.reports.stock({ location_id: location!.id, low_only: true }),
    enabled: location !== null && canViewStockReport,
  })
  const lowStockCount = stockQuery.data?.rows.length ?? 0

  // Today is unconditional; everything else is filtered against `sections` per
  // `SECTION_RULES` — an item simply never renders when its permission isn't held, which
  // is what makes navigating to a hidden section impossible (there's no button to click).
  const navSections: AppSidebarNavSection[] = [
    {
      eyebrow: 'Operations',
      items: [
        {
          key: 'today',
          label: 'Today',
          href: pathForSection('today'),
          count: lowStockCount > 0 ? lowStockCount : undefined,
        },
        ...(canManageCatalog ? [{ key: 'catalog', label: 'Catalog', href: pathForSection('catalog') }] : []),
        ...(canManageUsers || canManageRoles ? [{ key: 'users', label: 'Users', href: pathForSection('users') }] : []),
        ...(canManageLocations
          ? [{ key: 'locations', label: 'Locations & Registers', href: pathForSection('locations') }]
          : []),
        ...(canManageSettings ? [{ key: 'settings', label: 'Settings', href: pathForSection('settings') }] : []),
        ...(canCloseDay ? [{ key: 'day', label: 'End of Day', href: pathForSection('day') }] : []),
      ],
    },
    {
      eyebrow: 'Insights',
      items: [
        ...(canViewSalesReport || canViewStockReport
          ? [{ key: 'reports', label: 'Reports', href: pathForSection('reports') }]
          : []),
        ...(canViewAudit ? [{ key: 'audit', label: 'Audit', href: pathForSection('audit') }] : []),
      ],
    },
  ].filter((navSection) => navSection.items.length > 0)

  return (
    <main className="flex h-dvh bg-canvas">
      <AppSidebar
        sections={navSections}
        active={section}
        onNavigate={(key) => onNavigate(key as Section)}
        location={location}
        locations={locations}
        onLocationChange={onLocationChange}
        user={user ? { name: user.name } : null}
        onSignOut={onLogout}
      />

      <div className="flex-1 overflow-y-auto p-xl">
        {section === 'today' && (
          <TodaySection locationId={location?.id ?? null} sections={sections} onUnauthorized={onUnauthorized} />
        )}
        {section === 'catalog' && <CatalogSection onUnauthorized={onUnauthorized} />}
        {section === 'users' && (
          <UsersSection
            onUnauthorized={onUnauthorized}
            canManageUsers={canManageUsers}
            canManageRoles={canManageRoles}
          />
        )}
        {section === 'locations' && <PlacesSection onUnauthorized={onUnauthorized} />}
        {section === 'reports' && (
          <ReportsSection
            locationId={location?.id ?? null}
            onUnauthorized={onUnauthorized}
            canViewSales={canViewSalesReport}
            canViewStock={canViewStockReport}
          />
        )}
        {section === 'audit' && <AuditSection onUnauthorized={onUnauthorized} />}
        {section === 'settings' && <SettingsSection onUnauthorized={onUnauthorized} />}
        {section === 'day' && (
          <EndOfDaySection
            locationId={location?.id ?? null}
            isAdmin={user?.is_admin ?? false}
            onUnauthorized={onUnauthorized}
          />
        )}
      </div>
    </main>
  )
}
