'use client'

import { useState } from 'react'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '../../components/ui/tabs'
import { SalesReportView } from './SalesReportView'
import { StockReportView } from './StockReportView'

type Tab = 'sales' | 'stock'

/**
 * Reports: two tabs over the same `Tabs` rail CatalogSection/PlacesSection established.
 * Which location both reports read comes from the sidebar's location switcher
 * (`locationId`, owned by AdminApp) — the per-screen location pickers this section's
 * views used to carry are gone (the frozen contract's named switcher-relocation
 * exception), so this section no longer needs a locations fetch of its own. Task 11
 * gates each tab on its own permission (`report.sales.view` / `report.stock.view`) —
 * Shell only mounts this section when at least one is held.
 */
export function ReportsSection({
  locationId,
  onUnauthorized,
  canViewSales,
  canViewStock,
}: {
  locationId: string | null
  onUnauthorized: () => void
  canViewSales: boolean
  canViewStock: boolean
}) {
  const [tab, setTab] = useState<Tab>(canViewSales ? 'sales' : 'stock')

  return (
    <Tabs value={tab} onValueChange={(value) => setTab(value as Tab)}>
      <TabsList aria-label="Reports tabs">
        {canViewSales && <TabsTrigger value="sales">Sales</TabsTrigger>}
        {canViewStock && <TabsTrigger value="stock">Stock</TabsTrigger>}
      </TabsList>

      <div className="pt-lg">
        {canViewSales && (
          <TabsContent value="sales">
            <SalesReportView locationId={locationId} onUnauthorized={onUnauthorized} />
          </TabsContent>
        )}
        {canViewStock && (
          <TabsContent value="stock">
            <StockReportView locationId={locationId} onUnauthorized={onUnauthorized} />
          </TabsContent>
        )}
      </div>
    </Tabs>
  )
}
