'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { ApiError, api } from '../../lib/api'
import { SalesReportView } from './SalesReportView'
import { StockReportView } from './StockReportView'

type Tab = 'sales' | 'stock'

/**
 * Reports (Task 11): two tabs over the same tab-rail chrome CatalogSection/PlacesSection
 * established, sharing one locations fetch (both reports need it for their own location
 * picker).
 */
export function ReportsSection({ onUnauthorized }: { onUnauthorized: () => void }) {
  const [tab, setTab] = useState<Tab>('sales')
  const locations = useQuery({ queryKey: ['admin', 'locations'], queryFn: api.locations.list })

  useEffect(() => {
    if (locations.error instanceof ApiError && locations.error.status === 401) onUnauthorized()
  }, [locations.error, onUnauthorized])

  if (locations.isLoading) return <p className="muted">Loading…</p>
  if (locations.isError) return <p className="error">Could not load locations.</p>

  return (
    <div className="menu-grid">
      <nav className="menu-rail" aria-label="Reports tabs">
        <button
          type="button"
          className={`menu-rail-tab${tab === 'sales' ? ' active' : ''}`}
          aria-pressed={tab === 'sales'}
          onClick={() => setTab('sales')}
        >
          Sales
        </button>
        <button
          type="button"
          className={`menu-rail-tab${tab === 'stock' ? ' active' : ''}`}
          aria-pressed={tab === 'stock'}
          onClick={() => setTab('stock')}
        >
          Stock
        </button>
      </nav>

      <div style={{ flex: 1 }}>
        {tab === 'sales' && <SalesReportView locations={locations.data ?? []} onUnauthorized={onUnauthorized} />}
        {tab === 'stock' && <StockReportView locations={locations.data ?? []} onUnauthorized={onUnauthorized} />}
      </div>
    </div>
  )
}
