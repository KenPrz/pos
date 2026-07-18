'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { ApiError, api, type Location, type SalesReportRow } from '../../lib/api'
import { centsToDecimalString, downloadCsv, toCsv } from '../../lib/csv'
import { cents, formatMoney } from '../../lib/money'

const CURRENCY = 'USD' // display only; the server owns all arithmetic
const fm = (n: number) => formatMoney(cents(n), CURRENCY)

type GroupBy = 'day' | 'category' | 'user'

const GROUP_BY_CHIPS: Array<{ id: GroupBy; label: string }> = [
  { id: 'day', label: 'Day' },
  { id: 'category', label: 'Category' },
  { id: 'user', label: 'User' },
]

function isoDate(d: Date): string {
  return d.toISOString().slice(0, 10)
}

/** Last 7 days (inclusive), the brief's default range. */
function defaultRange(): { from: string; to: string } {
  const to = new Date()
  const from = new Date(to)
  from.setDate(from.getDate() - 6)
  return { from: isoDate(from), to: isoDate(to) }
}

/**
 * The sales report (Task 11): date range + location + a DAY/CATEGORY/USER group-by,
 * over the SalesReport endpoint from Task 6. `basis` on the response ('ledger' for
 * day/user, 'lines' for category) is surfaced as its own label so the two kinds of
 * number are never silently conflated — see SalesReportResource.php's doc comment.
 */
export function SalesReportView({
  locations,
  onUnauthorized,
}: {
  locations: Location[]
  onUnauthorized: () => void
}) {
  const initialRange = useMemo(defaultRange, [])
  const [from, setFrom] = useState(initialRange.from)
  const [to, setTo] = useState(initialRange.to)
  const [locationId, setLocationId] = useState(locations[0]?.id ?? '')
  const [groupBy, setGroupBy] = useState<GroupBy>('day')

  // Locations can arrive after this view's first render (ReportsSection fetches them
  // alongside) — pick up the first one once it does rather than leaving the picker
  // permanently blank on an empty initial array.
  useEffect(() => {
    if (!locationId && locations[0]) setLocationId(locations[0].id)
  }, [locations, locationId])

  const query = useQuery({
    queryKey: ['admin', 'reports', 'sales', locationId, from, to, groupBy],
    queryFn: () => api.reports.sales({ location_id: locationId, from, to, group_by: groupBy }),
    enabled: locationId !== '',
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

  return (
    <section className="form-panel">
      <header className="row">
        <h2>Sales</h2>
        <button type="button" className="btn btn-submit" onClick={exportCsv} disabled={!query.data}>
          Export CSV
        </button>
      </header>

      <div className="btn-row">
        <label htmlFor="sales-from">
          From
          <input id="sales-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </label>
        <label htmlFor="sales-to">
          To
          <input id="sales-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </label>
        <label htmlFor="sales-location">
          Location
          <select id="sales-location" value={locationId} onChange={(e) => setLocationId(e.target.value)}>
            {locations.map((l) => (
              <option key={l.id} value={l.id}>
                {l.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      <div className="btn-row" role="group" aria-label="Group by">
        {GROUP_BY_CHIPS.map((c) => (
          <button
            key={c.id}
            type="button"
            className={`btn btn-chip ${groupBy === c.id ? 'btn-secondary' : 'btn-utility'}`}
            aria-pressed={groupBy === c.id}
            onClick={() => setGroupBy(c.id)}
          >
            {c.label}
          </button>
        ))}
      </div>

      {basis && (
        <p className="muted">
          Basis: {basis === 'ledger' ? 'ledger (captured payments & refunds)' : 'line-based sales mix'}
        </p>
      )}

      {query.isLoading && <p className="muted">Loading…</p>}
      {query.isError && !(query.error instanceof ApiError && query.error.status === 401) && (
        <p className="error">Could not load the sales report.</p>
      )}

      {query.data && (
        <table className="bo-table">
          <thead>
            <tr>
              {isLines ? (
                <>
                  <th>Category</th>
                  <th>Qty sold</th>
                  <th>Line total</th>
                </>
              ) : (
                <>
                  <th>Bucket</th>
                  <th>Orders closed</th>
                  <th>Gross</th>
                  <th>Refunds</th>
                  <th>Net</th>
                </>
              )}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={isLines ? 3 : 5} className="muted">
                  No activity in this range.
                </td>
              </tr>
            ) : (
              rows.map((r) => (
                <tr key={r.bucket}>
                  {isLines ? (
                    <>
                      <td>{r.bucket}</td>
                      <td>{r.qty_sold}</td>
                      <td>{fm(r.line_total_cents ?? 0)}</td>
                    </>
                  ) : (
                    <>
                      <td>{r.bucket}</td>
                      <td>{r.orders_closed ?? '—'}</td>
                      <td>{fm(r.gross_cents ?? 0)}</td>
                      <td>{fm(r.refunds_cents ?? 0)}</td>
                      <td>{fm(r.net_cents ?? 0)}</td>
                    </>
                  )}
                </tr>
              ))
            )}
          </tbody>
          {query.data.totals && (
            <tfoot>
              <tr>
                {isLines ? (
                  <>
                    <td>
                      <strong>Total</strong>
                    </td>
                    <td>{query.data.totals.qty_sold}</td>
                    <td>{fm(query.data.totals.line_total_cents ?? 0)}</td>
                  </>
                ) : (
                  <>
                    <td>
                      <strong>Total</strong>
                    </td>
                    <td>{query.data.totals.orders_closed ?? '—'}</td>
                    <td>{fm(query.data.totals.gross_cents ?? 0)}</td>
                    <td>{fm(query.data.totals.refunds_cents ?? 0)}</td>
                    <td>{fm(query.data.totals.net_cents ?? 0)}</td>
                  </>
                )}
              </tr>
            </tfoot>
          )}
        </table>
      )}
    </section>
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
