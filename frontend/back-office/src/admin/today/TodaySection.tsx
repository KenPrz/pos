'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect, useMemo } from 'react'
import { DataTable } from '../../components/DataTable'
import { EmptyState } from '../../components/EmptyState'
import { SectionHeader } from '../../components/SectionHeader'
import { StatCard } from '../../components/StatCard'
import { StatusPill, type StatusPillTone } from '../../components/StatusPill'
import { Card, CardTitle } from '../../components/ui/card'
import { ApiError, api, type AuditLogEntry, type Register, type StockReportRow } from '../../lib/api'
import { getCurrency } from '../../lib/currency'
import { isoDate } from '../../lib/date'
import { cents, formatMoney } from '../../lib/money'

// display only; the server owns all arithmetic
const fm = (n: number) => formatMoney(cents(n), getCurrency())

/**
 * One row shape for the "Needs attention" `DataTable` — a low-stock variant and an
 * inactive register are different wire types, but the panel shows both the same way
 * (name, a detail column, a `StatusPill`), so they're normalized to this before
 * rendering rather than hand-rolling two branches of markup.
 */
type AttentionRow = { id: string; name: string; detail: string; tone: StatusPillTone; status: string }

function lowStockAttentionRow(row: StockReportRow): AttentionRow {
  return { id: `stock-${row.variant_id}`, name: row.name, detail: row.qty, tone: 'warning', status: 'Low stock' }
}

function inactiveRegisterAttentionRow(register: Register): AttentionRow {
  return { id: `register-${register.id}`, name: register.name, detail: '—', tone: 'error', status: 'Inactive' }
}

/** True when a query genuinely failed — a 401 is handled separately (`onUnauthorized`), never surfaced as an inline error. */
function failed(query: { isError: boolean; error: unknown }): boolean {
  return query.isError && !(query.error instanceof ApiError && query.error.status === 401)
}

/**
 * The Today landing (Task 2, back-office UI rework) — the post-login default section,
 * always visible in the sidebar (Shell never gates it) regardless of what a session can
 * see. RBAC v2 Task 11: each widget is its OWN `useQuery`, gated on the exact permission
 * its underlying endpoint requires server-side — `report.sales.view` for the sales KPI
 * trio, `report.stock.view` for the low-stock KPI and its attention rows,
 * `register.enroll` for the inactive-register attention rows (`ListRegistersController`
 * requires it, same as the Locations & Registers section), `audit.view` for the recent-
 * activity strip. A session missing one of these never fires that request at all — the
 * widget simply doesn't render — rather than firing it and reacting to a 403; a
 * partially-permissioned session therefore never sees the whole page fail because of one
 * widget it isn't entitled to. The one full-page `EmptyState` is reserved for the
 * genuine edge case where NONE of the four are held.
 */
export function TodaySection({
  locationId,
  sections,
  onUnauthorized,
}: {
  locationId: string | null
  sections: string[]
  onUnauthorized: () => void
}) {
  const canViewSales = sections.includes('report.sales.view')
  const canViewStock = sections.includes('report.stock.view')
  const canViewRegisters = sections.includes('register.enroll')
  const canViewAudit = sections.includes('audit.view')

  const today = useMemo(() => isoDate(new Date()), [])

  const salesQuery = useQuery({
    queryKey: ['admin', 'today', 'sales', locationId],
    queryFn: () => api.reports.sales({ location_id: locationId as string, from: today, to: today, group_by: 'day' }),
    enabled: locationId !== null && canViewSales,
  })
  const stockQuery = useQuery({
    queryKey: ['admin', 'today', 'stock', locationId],
    queryFn: () => api.reports.stock({ location_id: locationId as string, low_only: true }),
    enabled: locationId !== null && canViewStock,
  })
  // Not location-scoped on the wire (same list PlacesSection's Registers tab fetches,
  // so this shares its cache under the same key rather than firing a second request) —
  // but still gated on `locationId !== null` too: "no location selected" means this page
  // fetches NOTHING, matching the empty-state branch below, not just the two reports.
  const registersQuery = useQuery({
    queryKey: ['admin', 'registers'],
    queryFn: api.registers.list,
    enabled: locationId !== null && canViewRegisters,
  })
  const auditQuery = useQuery({
    queryKey: ['admin', 'today', 'audit'],
    queryFn: () => api.audit.list({ page: 1 }),
    enabled: locationId !== null && canViewAudit,
  })

  useEffect(() => {
    const unauthorized = [salesQuery.error, stockQuery.error, registersQuery.error, auditQuery.error].some(
      (error) => error instanceof ApiError && error.status === 401,
    )
    if (unauthorized) onUnauthorized()
  }, [salesQuery.error, stockQuery.error, registersQuery.error, auditQuery.error, onUnauthorized])

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

  // The genuine "nothing to show" case — a session holding none of the four reporting
  // permissions. Today stays reachable (the sidebar item is unconditional), but there is
  // nothing on this page it's entitled to fetch.
  if (!canViewSales && !canViewStock && !canViewRegisters && !canViewAudit) {
    return (
      <div className="flex flex-col gap-lg">
        {header}
        <EmptyState
          title="Nothing to show"
          description="This account doesn't hold any reporting permissions yet — ask an admin for report, stock, or audit access."
        />
      </div>
    )
  }

  // A single blocking spinner while any PERMITTED widget's first fetch is in flight —
  // disabled queries never report `isLoading: true` (no fetch ever starts), so this only
  // waits on work that's actually happening.
  const anyLoading =
    (canViewSales && salesQuery.isLoading) ||
    (canViewStock && stockQuery.isLoading) ||
    (canViewRegisters && registersQuery.isLoading) ||
    (canViewAudit && auditQuery.isLoading)

  if (anyLoading) {
    return (
      <div className="flex flex-col gap-lg">
        {header}
        <p className="type-body-sm text-ink-muted">Loading…</p>
      </div>
    )
  }

  const totals = salesQuery.data?.totals
  const lowStockRows = canViewStock ? (stockQuery.data?.rows ?? []) : []
  const inactiveRegisters = canViewRegisters
    ? (registersQuery.data ?? []).filter((r) => r.location_id === locationId && !r.is_active)
    : []
  const recentActivity = canViewAudit ? (auditQuery.data?.rows ?? []) : []
  const attentionRows: AttentionRow[] = [
    ...lowStockRows.map(lowStockAttentionRow),
    ...inactiveRegisters.map(inactiveRegisterAttentionRow),
  ]

  return (
    <div className="flex flex-col gap-xl">
      {header}

      {(canViewSales || canViewStock) && (
        <div className="grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-4">
          {canViewSales && !failed(salesQuery) && (
            <>
              <StatCard label="Net sales today" value={fm(totals?.net_cents ?? 0)} />
              <StatCard label="Orders closed" value={String(totals?.orders_closed ?? 0)} />
              <StatCard label="Refunds today" value={fm(totals?.refunds_cents ?? 0)} />
            </>
          )}
          {canViewStock && !failed(stockQuery) && <StatCard label="Low stock" value={String(lowStockRows.length)} />}
        </div>
      )}
      {canViewSales && failed(salesQuery) && <p className="type-body-sm text-error">Could not load today's sales.</p>}
      {canViewStock && failed(stockQuery) && <p className="type-body-sm text-error">Could not load today's stock.</p>}

      {(canViewStock || canViewRegisters) && (
        <Card>
          <CardTitle className="mb-md">Needs attention</CardTitle>
          <DataTable<AttentionRow>
            columns={[
              { key: 'name', header: 'Name', render: (r) => r.name },
              { key: 'detail', header: 'Qty', render: (r) => r.detail },
              { key: 'status', header: 'Status', render: (r) => <StatusPill tone={r.tone}>{r.status}</StatusPill> },
            ]}
            rows={attentionRows}
            rowKey={(r) => r.id}
            empty={{ title: 'All clear', description: 'Nothing needs your attention right now.' }}
          />
        </Card>
      )}
      {canViewRegisters && failed(registersQuery) && (
        <p className="type-body-sm text-error">Could not load register status.</p>
      )}

      {canViewAudit && !failed(auditQuery) && (
        <div>
          <CardTitle className="mb-md">Recent activity</CardTitle>
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
      )}
      {canViewAudit && failed(auditQuery) && <p className="type-body-sm text-error">Could not load recent activity.</p>}
    </div>
  )
}
