'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect } from 'react'
import { DataTable } from '../../components/DataTable'
import { EmptyState } from '../../components/EmptyState'
import { SectionHeader } from '../../components/SectionHeader'
import { StatCard } from '../../components/StatCard'
import { StatusPill } from '../../components/StatusPill'
import { Card } from '../../components/ui/card'
import { ApiError, api, type AuditLogEntry } from '../../lib/api'
import { cents, formatMoney } from '../../lib/money'

const CURRENCY = 'USD' // display only; the server owns all arithmetic
const fm = (n: number) => formatMoney(cents(n), CURRENCY)

/**
 * The Today landing (Task 2, back-office UI rework) — the post-login default section.
 * Composed ENTIRELY from existing endpoints via `api.today.overview`: zero new backend,
 * new LABELS only (the design spec's frozen contract names this screen as one of exactly
 * three permitted exceptions). Every number here is the SAME data Reports/Stock/Audit
 * already show, just gathered onto one glance.
 */
export function TodaySection({
  locationId,
  onUnauthorized,
}: {
  locationId: string | null
  onUnauthorized: () => void
}) {
  const query = useQuery({
    queryKey: ['admin', 'today', locationId],
    queryFn: () => api.today.overview(locationId as string),
    enabled: locationId !== null,
  })

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 401) onUnauthorized()
  }, [query.error, onUnauthorized])

  const header = <SectionHeader title="Today" subline="What's happening at this location right now." />

  if (locationId === null) {
    return (
      <div className="flex flex-col gap-lg">
        {header}
        <EmptyState
          title="No location selected"
          description="Pick a location from the sidebar to see today's numbers."
        />
      </div>
    )
  }

  if (query.isLoading) {
    return (
      <div className="flex flex-col gap-lg">
        {header}
        <p className="type-body-sm text-ink-muted">Loading…</p>
      </div>
    )
  }

  if (query.isError && !(query.error instanceof ApiError && query.error.status === 401)) {
    return (
      <div className="flex flex-col gap-lg">
        {header}
        <p className="type-body-sm text-error">Could not load today's overview.</p>
      </div>
    )
  }

  const data = query.data
  const totals = data?.sales.totals
  const lowStockRows = data?.stock.rows ?? []
  const inactiveRegisters = (data?.registers ?? []).filter(
    (r) => r.location_id === locationId && !r.is_active
  )
  const recentActivity = data?.audit.rows ?? []
  const needsAttention = lowStockRows.length > 0 || inactiveRegisters.length > 0

  return (
    <div className="flex flex-col gap-xl">
      {header}

      <div className="grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Net sales today" value={fm(totals?.net_cents ?? 0)} />
        <StatCard label="Orders closed" value={String(totals?.orders_closed ?? 0)} />
        <StatCard label="Refunds today" value={fm(totals?.refunds_cents ?? 0)} />
        <StatCard label="Low stock" value={String(lowStockRows.length)} />
      </div>

      <Card>
        <h2 className="type-card-title mb-md text-ink">Needs attention</h2>
        {needsAttention ? (
          <ul className="flex flex-col gap-sm">
            {lowStockRows.map((row) => (
              <li key={row.variant_id} className="flex items-center justify-between gap-md">
                <span className="type-body-sm text-ink">
                  {row.name} — {row.qty}
                </span>
                <StatusPill tone="warning">Low stock</StatusPill>
              </li>
            ))}
            {inactiveRegisters.map((r) => (
              <li key={r.id} className="flex items-center justify-between gap-md">
                <span className="type-body-sm text-ink">{r.name}</span>
                <StatusPill tone="error">Inactive</StatusPill>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState title="All clear" description="Nothing needs your attention right now." />
        )}
      </Card>

      <div>
        <h2 className="type-card-title mb-md text-ink">Recent activity</h2>
        <DataTable<AuditLogEntry>
          columns={[
            { key: 'created_at', header: 'When', render: (r) => r.created_at },
            { key: 'action', header: 'Action', render: (r) => r.action },
            { key: 'user_name', header: 'User', render: (r) => r.user_name ?? '—' },
          ]}
          rows={recentActivity}
          rowKey={(r) => r.id}
          empty={{ title: 'No recent activity', description: 'Nothing has been recorded yet.' }}
        />
      </div>
    </div>
  )
}
