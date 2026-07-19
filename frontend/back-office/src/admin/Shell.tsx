'use client'

import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { AppSidebar, type AppSidebarNavSection } from '../components/AppSidebar'
import { api, type AdminUser, type Location } from '../lib/api'
import { AuditSection } from './audit/AuditSection'
import { CatalogSection } from './catalog/CatalogSection'
import { PlacesSection } from './places/PlacesSection'
import { ReportsSection } from './reports/ReportsSection'
import { TodaySection } from './today/TodaySection'
import { UsersSection } from './users/UsersSection'

export type Section = 'today' | 'catalog' | 'users' | 'locations' | 'reports' | 'audit'

export function Shell({
  user,
  onLogout,
  onUnauthorized,
  location,
  locations,
  onLocationChange,
}: {
  user: AdminUser | null
  onLogout: () => void
  // Threaded down to every section's queries/mutations: any 401 anywhere in the shell
  // drops back to the login screen the same way the register's onSessionExpired does.
  onUnauthorized: () => void
  // The sidebar's location switcher is THE location picker (AdminApp owns the state,
  // Task 5 threads it into Reports/Stock; until then a section may ignore it — Today
  // is the first consumer).
  location: Location | null
  locations: Location[]
  onLocationChange: (id: string) => void
}) {
  const [section, setSection] = useState<Section>('today')

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

  const navSections: AppSidebarNavSection[] = [
    {
      eyebrow: 'Operations',
      items: [
        { key: 'today', label: 'Today', count: lowStockCount > 0 ? lowStockCount : undefined },
        { key: 'catalog', label: 'Catalog' },
        { key: 'users', label: 'Users' },
        { key: 'locations', label: 'Locations & Registers' },
      ],
    },
    {
      eyebrow: 'Insights',
      items: [
        { key: 'reports', label: 'Reports' },
        { key: 'audit', label: 'Audit' },
      ],
    },
  ]

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
        {section === 'users' && <UsersSection onUnauthorized={onUnauthorized} />}
        {section === 'locations' && <PlacesSection onUnauthorized={onUnauthorized} />}
        {section === 'reports' && <ReportsSection onUnauthorized={onUnauthorized} />}
        {section === 'audit' && <AuditSection onUnauthorized={onUnauthorized} />}
      </div>
    </main>
  )
}
