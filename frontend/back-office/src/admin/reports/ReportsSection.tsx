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
 * exception), so this section no longer needs a locations fetch of its own.
 */
export function ReportsSection({
  locationId,
  onUnauthorized,
}: {
  locationId: string | null
  onUnauthorized: () => void
}) {
  const [tab, setTab] = useState<Tab>('sales')

  return (
    <Tabs value={tab} onValueChange={(value) => setTab(value as Tab)}>
      <TabsList aria-label="Reports tabs">
        <TabsTrigger value="sales">Sales</TabsTrigger>
        <TabsTrigger value="stock">Stock</TabsTrigger>
      </TabsList>

      <div className="pt-lg">
        <TabsContent value="sales">
          <SalesReportView locationId={locationId} onUnauthorized={onUnauthorized} />
        </TabsContent>
        <TabsContent value="stock">
          <StockReportView locationId={locationId} onUnauthorized={onUnauthorized} />
        </TabsContent>
      </div>
    </Tabs>
  )
}
