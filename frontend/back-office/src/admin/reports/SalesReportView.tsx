'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { DataTable, type DataTableColumn } from '../../components/DataTable'
import { EmptyState } from '../../components/EmptyState'
import { FieldRow } from '../../components/FieldRow'
import { StatusPill } from '../../components/StatusPill'
import { Button } from '../../components/ui/button'
import { CardTitle } from '../../components/ui/card'
import { Input } from '../../components/ui/input'
import { Tabs, TabsList, TabsTrigger } from '../../components/ui/tabs'
import { ApiError, api, type SalesReportRow } from '../../lib/api'
import { centsToDecimalString, downloadCsv, toCsv } from '../../lib/csv'
import { isoDate } from '../../lib/date'
import { cents, formatMoney } from '../../lib/money'

const CURRENCY = 'USD' // display only; the server owns all arithmetic
const fm = (n: number) => formatMoney(cents(n), CURRENCY)

type GroupBy = 'day' | 'category' | 'user'

const GROUP_BY_TABS: Array<{ id: GroupBy; label: string }> = [
  { id: 'day', label: 'Day' },
  { id: 'category', label: 'Category' },
  { id: 'user', label: 'User' },
]

/** Last 7 days (inclusive), the brief's default range. */
function defaultRange(): { from: string; to: string } {
  const to = new Date()
  const from = new Date(to)
  from.setDate(from.getDate() - 6)
  return { from: isoDate(from), to: isoDate(to) }
}

/**
 * The sales report: date range + a DAY/CATEGORY/USER group-by (a `Tabs` rail — same
 * labels the old chips carried), over the SalesReport endpoint. Which location comes
 * from the sidebar's location switcher via the `locationId` prop (the frozen contract's
 * named switcher-relocation exception) — it's part of the query key, so switching
 * locations in the sidebar refetches this report. `basis` on the response ('ledger' for
 * day/user, 'lines' for category) is surfaced as its own `StatusPill` so the two kinds
 * of number are never silently conflated — see SalesReportResource.php's doc comment.
 */
export function SalesReportView({
  locationId,
  onUnauthorized,
}: {
  locationId: string | null
  onUnauthorized: () => void
}) {
  const initialRange = useMemo(defaultRange, [])
  const [from, setFrom] = useState(initialRange.from)
  const [to, setTo] = useState(initialRange.to)
  const [groupBy, setGroupBy] = useState<GroupBy>('day')

  const query = useQuery({
    queryKey: ['admin', 'reports', 'sales', locationId, from, to, groupBy],
    queryFn: () => api.reports.sales({ location_id: locationId as string, from, to, group_by: groupBy }),
    enabled: locationId !== null,
  })

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 401) onUnauthorized()
  }, [query.error, onUnauthorized])

  const rows = query.data?.rows ?? []
  const basis = query.data?.basis
  const isLines = basis === 'lines'

  const exportCsv = () => {
    if (!query.data) return
    const headers = isLines
      ? ['Category', 'Qty sold', 'Line total']
      : ['Bucket', 'Orders closed', 'Gross', 'Refunds', 'Net']
    const csvRows: Array<Array<string | number>> = rows.map((r) => rowToCsv(r, isLines))
    downloadCsv(`sales-report-${groupBy}-${from}-to-${to}.csv`, toCsv(headers, csvRows))
  }

  const columns: DataTableColumn<SalesReportRow>[] = isLines
    ? [
        { key: 'bucket', header: 'Category', render: (r) => r.bucket },
        { key: 'qty_sold', header: 'Qty sold', render: (r) => r.qty_sold },
        { key: 'line_total_cents', header: 'Line total', render: (r) => fm(r.line_total_cents ?? 0) },
      ]
    : [
        { key: 'bucket', header: 'Bucket', render: (r) => r.bucket },
        { key: 'orders_closed', header: 'Orders closed', render: (r) => r.orders_closed ?? '—' },
        { key: 'gross_cents', header: 'Gross', render: (r) => fm(r.gross_cents ?? 0) },
        { key: 'refunds_cents', header: 'Refunds', render: (r) => fm(r.refunds_cents ?? 0) },
        { key: 'net_cents', header: 'Net', render: (r) => fm(r.net_cents ?? 0) },
      ]

  const totals = query.data?.totals
  const footer = totals
    ? isLines
      ? ['Total', totals.qty_sold, fm(totals.line_total_cents ?? 0)]
      : [
          'Total',
          totals.orders_closed ?? '—',
          fm(totals.gross_cents ?? 0),
          fm(totals.refunds_cents ?? 0),
          fm(totals.net_cents ?? 0),
        ]
    : undefined

  return (
    <div className="flex flex-col gap-lg">
      <div className="flex items-center justify-between gap-md">
        <CardTitle>Sales</CardTitle>
        <Button type="button" variant="ghost" onClick={exportCsv} disabled={!query.data}>
          Export CSV
        </Button>
      </div>

      <div className="flex flex-wrap items-end gap-md">
        <div className="w-[200px]">
          <FieldRow label="From">
            <Input id="sales-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </FieldRow>
        </div>
        <div className="w-[200px]">
          <FieldRow label="To">
            <Input id="sales-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </FieldRow>
        </div>
      </div>

      <Tabs value={groupBy} onValueChange={(value) => setGroupBy(value as GroupBy)}>
        <TabsList aria-label="Group by">
          {GROUP_BY_TABS.map((t) => (
            <TabsTrigger key={t.id} value={t.id}>
              {t.label}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      {basis && (
        <div>
          <StatusPill tone="info">
            {basis === 'ledger' ? 'Basis: ledger (captured payments & refunds)' : 'Basis: line-based sales mix'}
          </StatusPill>
        </div>
      )}

      {locationId === null && (
        <EmptyState title="No location selected" description="Pick a location from the sidebar to run this report." />
      )}
      {query.isLoading && <p className="type-body-sm text-ink-muted">Loading…</p>}
      {query.isError && !(query.error instanceof ApiError && query.error.status === 401) && (
        <p className="type-body-sm text-error">Could not load the sales report.</p>
      )}

      {query.data && (
        <DataTable<SalesReportRow>
          columns={columns}
          rows={rows}
          rowKey={(r) => r.bucket}
          empty={{ title: 'No activity in this range.' }}
          footer={footer}
        />
      )}
    </div>
  )
}

function rowToCsv(r: SalesReportRow, isLines: boolean): Array<string | number> {
  return isLines
    ? [r.bucket, r.qty_sold ?? '0', centsToDecimalString(r.line_total_cents ?? 0)]
    : [
        r.bucket,
        r.orders_closed ?? '',
        centsToDecimalString(r.gross_cents ?? 0),
        centsToDecimalString(r.refunds_cents ?? 0),
        centsToDecimalString(r.net_cents ?? 0),
      ]
}
