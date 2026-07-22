'use client'

import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { AppSidebar, type AppSidebarNavSection } from '../components/AppSidebar'
import { api, type AdminUser, type Location } from '../lib/api'
import { AuditSection } from './audit/AuditSection'
import { CatalogSection } from './catalog/CatalogSection'
import { PlacesSection } from './places/PlacesSection'
import { ReportsSection } from './reports/ReportsSection'
import { SettingsSection } from './settings/SettingsSection'
import { TodaySection } from './today/TodaySection'
import { UsersSection } from './users/UsersSection'

export type Section = 'today' | 'catalog' | 'users' | 'locations' | 'reports' | 'audit' | 'settings'

/**
 * Section-permission → sidebar item, exact mapping from the brief (RBAC v2 Task 11).
 * `today` carries no entry — it's always visible regardless of `sections`. Everything
 * else needs at least one of its listed permissions held; `Shell` OR's the list, but the
 * two composite sections (Users, Reports) also read their two permissions individually
 * to decide which *tab* to show inside the section.
 */
const SECTION_RULES: Record<Exclude<Section, 'today'>, string[]> = {
  catalog: ['catalog.manage'],
  users: ['user.manage', 'role.manage'],
  locations: ['location.manage', 'register.enroll'],
  reports: ['report.sales.view', 'report.stock.view'],
  audit: ['audit.view'],
  settings: ['settings.manage'],
}

function hasAny(sections: string[], required: string[]): boolean {
  return required.some((permission) => sections.includes(permission))
}

export function Shell({
  user,
  sections,
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
  const [section, setSection] = useState<Section>('today')

  const canManageCatalog = hasAny(sections, SECTION_RULES.catalog)
  const canManageUsers = sections.includes('user.manage')
  const canManageRoles = sections.includes('role.manage')
  const canManageLocations = hasAny(sections, SECTION_RULES.locations)
  const canViewSalesReport = sections.includes('report.sales.view')
  const canViewStockReport = sections.includes('report.stock.view')
  const canViewAudit = hasAny(sections, SECTION_RULES.audit)
  const canManageSettings = hasAny(sections, SECTION_RULES.settings)

  // Same data Today's own screen fetches (`api.today.overview`) — sharing the queryKey
  // means React Query dedupes this against TodaySection's request rather than firing a
  // second one, so the nav's low-stock count costs nothing extra over rendering Today
  // itself. Runs regardless of which section is active, so the badge stays honest even
  // while parked on Catalog or Reports.
  const todayQuery = useQuery({
    queryKey: ['admin', 'today', location?.id ?? null],
    queryFn: () => api.today.overview(location!.id),
    enabled: location !== null,
  })
  const lowStockCount = todayQuery.data?.stock.rows.length ?? 0

  // Today is unconditional; everything else is filtered against `sections` per
  // `SECTION_RULES` — an item simply never renders when its permission isn't held, which
  // is what makes navigating to a hidden section impossible (there's no button to click).
  const navSections: AppSidebarNavSection[] = [
    {
      eyebrow: 'Operations',
      items: [
        { key: 'today', label: 'Today', count: lowStockCount > 0 ? lowStockCount : undefined },
        ...(canManageCatalog ? [{ key: 'catalog', label: 'Catalog' }] : []),
        ...(canManageUsers || canManageRoles ? [{ key: 'users', label: 'Users' }] : []),
        ...(canManageLocations ? [{ key: 'locations', label: 'Locations & Registers' }] : []),
        ...(canManageSettings ? [{ key: 'settings', label: 'Settings' }] : []),
      ],
    },
    {
      eyebrow: 'Insights',
      items: [
        ...(canViewSalesReport || canViewStockReport ? [{ key: 'reports', label: 'Reports' }] : []),
        ...(canViewAudit ? [{ key: 'audit', label: 'Audit' }] : []),
      ],
    },
  ].filter((navSection) => navSection.items.length > 0)

  return (
    <main className="flex h-dvh bg-canvas">
      <AppSidebar
        sections={navSections}
        active={section}
        onNavigate={(key) => setSection(key as Section)}
        location={location}
        locations={locations}
        onLocationChange={onLocationChange}
        user={user ? { name: user.name } : null}
        onSignOut={onLogout}
      />

      <div className="flex-1 overflow-y-auto p-xl">
        {section === 'today' && (
          <TodaySection locationId={location?.id ?? null} onUnauthorized={onUnauthorized} />
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
      </div>
    </main>
  )
}
