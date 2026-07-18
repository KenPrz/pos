'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { ApiError, api, type Location } from '../../lib/api'

/**
 * On-hand quantities at a location (Task 11), over the StockReport endpoint from Task 6.
 * `low` rows get the report's one warm-accent signal — see `.low-stock-row` in
 * index.css — because a low variant is the one thing here that calls for action
 * (reorder), unlike everything else on this screen which is read-only.
 */
export function StockReportView({
  locations,
  onUnauthorized,
}: {
  locations: Location[]
  onUnauthorized: () => void
}) {
  const [locationId, setLocationId] = useState(locations[0]?.id ?? '')
  const [lowOnly, setLowOnly] = useState(false)

  useEffect(() => {
    if (!locationId && locations[0]) setLocationId(locations[0].id)
  }, [locations, locationId])

  const query = useQuery({
    queryKey: ['admin', 'reports', 'stock', locationId, lowOnly],
    queryFn: () => api.reports.stock({ location_id: locationId, low_only: lowOnly }),
    enabled: locationId !== '',
  })

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 401) onUnauthorized()
  }, [query.error, onUnauthorized])

  const rows = query.data?.rows ?? []

  return (
    <section className="form-panel">
      <header className="row">
        <h2>Stock</h2>
      </header>

      <div className="btn-row">
        <label htmlFor="stock-location">
          Location
          <select id="stock-location" value={locationId} onChange={(e) => setLocationId(e.target.value)}>
            {locations.map((l) => (
              <option key={l.id} value={l.id}>
                {l.name}
              </option>
            ))}
          </select>
        </label>
        <button
          type="button"
          className={`btn btn-chip ${lowOnly ? 'btn-secondary' : 'btn-utility'}`}
          aria-pressed={lowOnly}
          onClick={() => setLowOnly((v) => !v)}
        >
          Low only
        </button>
      </div>

      {query.isLoading && <p className="muted">Loading…</p>}
      {query.isError && !(query.error instanceof ApiError && query.error.status === 401) && (
        <p className="error">Could not load the stock report.</p>
      )}

      {query.data &&
        (rows.length === 0 ? (
          <p className="muted">No stock rows for this location.</p>
        ) : (
          <table className="bo-table">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Name</th>
                <th>Qty</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.variant_id} className={r.low ? 'low-stock-row' : undefined}>
                  <td>{r.sku}</td>
                  <td>{r.name}</td>
                  <td>
                    {r.qty}
                    {r.low && ' — LOW'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ))}
    </section>
  )
}
