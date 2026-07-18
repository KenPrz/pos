'use client'

import { useState } from 'react'
import type { AdminUser } from '../lib/api'
import { CatalogSection } from './catalog/CatalogSection'
import { UsersSection } from './users/UsersSection'
import { PlacesSection } from './places/PlacesSection'
import { ReportsSection } from './reports/ReportsSection'
import { AuditSection } from './audit/AuditSection'

export type Section = 'catalog' | 'users' | 'locations' | 'reports' | 'audit'

const NAV_ITEMS: Array<{ id: Section; label: string }> = [
  { id: 'catalog', label: 'Catalog' },
  { id: 'users', label: 'Users' },
  { id: 'locations', label: 'Locations & Registers' },
  { id: 'reports', label: 'Reports' },
  { id: 'audit', label: 'Audit' },
]

export function Shell({
  user,
  onLogout,
  onUnauthorized,
}: {
  user: AdminUser | null
  onLogout: () => void
  // Threaded down to every section's queries/mutations (Task 9's CatalogSection first):
  // any 401 anywhere in the shell drops back to the login screen the same way the
  // register's onSessionExpired does.
  onUnauthorized: () => void
}) {
  const [section, setSection] = useState<Section>('catalog')

  return (
    <main className="shell">
      <header className="carbon-bar">
        <span className="pos-pill">POS</span>
        <span className="carbon-bar-section">· Back Office</span>
        <span className="carbon-bar-right">
          {user && <span className="carbon-bar-user">{user.name}</span>}
          <button type="button" className="btn btn-secondary btn-clockout" onClick={onLogout}>
            Sign out
          </button>
        </span>
      </header>

      <div className="plate chamfer register-body">
        {/* Reuses the menu grid's rail/tab layout (flex row, fixed-width rail) from
            DESIGN.md's food-mode menu — it's generic sidebar chrome, not menu-specific,
            so borrowing it here avoids inventing a second nav idiom. */}
        <div className="menu-grid">
          <nav className="menu-rail" aria-label="Sections">
            {NAV_ITEMS.map((item) => (
              <button
                key={item.id}
                type="button"
                className={`menu-rail-tab${item.id === section ? ' active' : ''}`}
                aria-current={item.id === section}
                onClick={() => setSection(item.id)}
              >
                {item.label}
              </button>
            ))}
          </nav>

          {section === 'catalog' && (
            // CatalogSection renders its own nested menu-grid (section rail vs. catalog
            // tab rail are two independent nav levels) — it needs the same flex:1 the
            // placeholder panel below gets, or it sizes to content instead of filling
            // the row next to Shell's own rail.
            <div style={{ flex: 1 }}>
              <CatalogSection onUnauthorized={onUnauthorized} />
            </div>
          )}
          {section === 'users' && (
            <div style={{ flex: 1 }}>
              <UsersSection onUnauthorized={onUnauthorized} />
            </div>
          )}
          {section === 'locations' && (
            <div style={{ flex: 1 }}>
              <PlacesSection onUnauthorized={onUnauthorized} />
            </div>
          )}
          {section === 'reports' && (
            <div style={{ flex: 1 }}>
              <ReportsSection onUnauthorized={onUnauthorized} />
            </div>
          )}
          {section === 'audit' && (
            <div style={{ flex: 1 }}>
              <AuditSection onUnauthorized={onUnauthorized} />
            </div>
          )}
        </div>
      </div>
    </main>
  )
}
