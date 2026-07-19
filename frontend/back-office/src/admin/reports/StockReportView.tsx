'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { EmptyState } from '../../components/EmptyState'
import { Button } from '../../components/ui/button'
import { CardTitle } from '../../components/ui/card'
import { ApiError, api, type StockReportRow } from '../../lib/api'

/**
 * On-hand quantities at the sidebar switcher's location (`locationId`, part of the query
 * key — switching locations refetches; the per-screen location picker is gone, the
 * frozen contract's named switcher-relocation exception). `low` rows keep their " — LOW"
 * marker and take the warning-semantic ink — a low variant is the one thing here that
 * calls for action (reorder), unlike everything else on this read-only screen.
 */
export function StockReportView({
  locationId,
  onUnauthorized,
}: {
  locationId: string | null
  onUnauthorized: () => void
}) {
  const [lowOnly, setLowOnly] = useState(false)

  const query = useQuery({
    queryKey: ['admin', 'reports', 'stock', locationId, lowOnly],
    queryFn: () => api.reports.stock({ location_id: locationId as string, low_only: lowOnly }),
    enabled: locationId !== null,
  })

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 401) onUnauthorized()
  }, [query.error, onUnauthorized])

  const rows = query.data?.rows ?? []

  return (
    <div className="flex flex-col gap-lg">
      <CardTitle>Stock</CardTitle>

      <div>
        <Button
          type="button"
          variant={lowOnly ? 'secondary' : 'ghost'}
          aria-pressed={lowOnly}
          onClick={() => setLowOnly((v) => !v)}
        >
          Low only
        </Button>
      </div>

      {locationId === null && (
        <EmptyState title="No location selected" description="Pick a location from the sidebar to run this report." />
      )}
      {query.isLoading && <p className="type-body-sm text-ink-muted">Loading…</p>}
      {query.isError && !(query.error instanceof ApiError && query.error.status === 401) && (
        <p className="type-body-sm text-error">Could not load the stock report.</p>
      )}

      {query.data && (
        <DataTable<StockReportRow>
          columns={[
            { key: 'sku', header: 'SKU', render: (r) => r.sku },
            { key: 'name', header: 'Name', render: (r) => r.name },
            {
              key: 'qty',
              header: 'Qty',
              render: (r) => (
                <span className={r.low ? 'font-semibold text-warning-ink' : undefined}>
                  {r.qty}
                  {r.low && ' — LOW'}
                </span>
              ),
            },
          ]}
          rows={rows}
          rowKey={(r) => r.variant_id}
          empty={{ title: 'No stock rows for this location.' }}
        />
      )}
    </div>
  )
}
