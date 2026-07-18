'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { ApiError, api, type AuditLogEntry } from '../../lib/api'

// The known entity types the audit log records against (docs/02-data-model.md's
// auditable set) — a select over these rather than free text, since a typo'd entity
// type would silently return zero rows rather than error.
const ENTITY_TYPES = [
  'Order',
  'OrderLine',
  'OrderDiscount',
  'Payment',
  'Refund',
  'Shift',
  'Register',
  'Location',
  'User',
  'Product',
  'ProductVariant',
  'Category',
  'TaxRate',
  'Discount',
  'Modifier',
  'ModifierGroup',
]

type Filters = {
  entityType: string
  entityId: string
  userId: string
  action: string
  from: string
  to: string
}

const EMPTY_FILTERS: Filters = { entityType: '', entityId: '', userId: '', action: '', from: '', to: '' }

/**
 * The audit-log viewer (Task 11), over ListAuditLog (Task 7). Filters are staged in
 * local state and only take effect on FILTER — applying on every keystroke would refetch
 * per character, which the free-text entity id / user id / action fields would make
 * noisy. Pagination is has_more-driven LOAD MORE, appending pages rather than replacing
 * them, so scrolling back doesn't lose earlier rows.
 */
export function AuditSection({ onUnauthorized }: { onUnauthorized: () => void }) {
  const [filters, setFilters] = useState<Filters>(EMPTY_FILTERS)
  const [appliedFilters, setAppliedFilters] = useState<Filters>(EMPTY_FILTERS)
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<AuditLogEntry[]>([])

  const params = {
    entity_type: appliedFilters.entityType || undefined,
    entity_id: appliedFilters.entityId || undefined,
    user_id: appliedFilters.userId || undefined,
    action: appliedFilters.action || undefined,
    from: appliedFilters.from || undefined,
    to: appliedFilters.to || undefined,
    page,
  }

  const query = useQuery({
    queryKey: ['admin', 'audit', appliedFilters, page],
    queryFn: () => api.audit.list(params),
  })

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 401) onUnauthorized()
  }, [query.error, onUnauthorized])

  // Page 1 replaces the accumulated rows (a fresh filter or the first load); any later
  // page appends to what LOAD MORE has already brought in.
  useEffect(() => {
    if (!query.data) return
    setRows((prev) => (page === 1 ? query.data.rows : [...prev, ...query.data.rows]))
  }, [query.data, page])

  const applyFilters = () => {
    setAppliedFilters(filters)
    setPage(1)
    // Clear synchronously rather than waiting on the reactive append effect above —
    // between the click and the response landing (or forever, if it errors), the table
    // must not go on showing the previous filter's rows as if they matched the new one.
    setRows([])
  }

  const hasMore = query.data?.has_more ?? false

  return (
    <section className="form-panel">
      <header className="row">
        <h2>Audit log</h2>
      </header>

      <div className="btn-row">
        <label htmlFor="audit-entity-type">
          Entity type
          <select
            id="audit-entity-type"
            value={filters.entityType}
            onChange={(e) => setFilters((f) => ({ ...f, entityType: e.target.value }))}
          >
            <option value="">All</option>
            {ENTITY_TYPES.map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>
        </label>
        <label htmlFor="audit-entity-id">
          Entity id
          <input
            id="audit-entity-id"
            value={filters.entityId}
            onChange={(e) => setFilters((f) => ({ ...f, entityId: e.target.value }))}
          />
        </label>
        <label htmlFor="audit-user-id">
          User id
          <input
            id="audit-user-id"
            value={filters.userId}
            onChange={(e) => setFilters((f) => ({ ...f, userId: e.target.value }))}
          />
        </label>
        <label htmlFor="audit-action">
          Action
          <input
            id="audit-action"
            value={filters.action}
            onChange={(e) => setFilters((f) => ({ ...f, action: e.target.value }))}
          />
        </label>
        <label htmlFor="audit-from">
          From
          <input id="audit-from" type="date" value={filters.from} onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value }))} />
        </label>
        <label htmlFor="audit-to">
          To
          <input id="audit-to" type="date" value={filters.to} onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value }))} />
        </label>
        <button type="button" className="btn btn-submit" onClick={applyFilters}>
          Filter
        </button>
      </div>

      {query.isLoading && page === 1 && <p className="muted">Loading…</p>}
      {query.isError && !(query.error instanceof ApiError && query.error.status === 401) && (
        <p className="error">Could not load the audit log.</p>
      )}

      {rows.length === 0 && !query.isLoading ? (
        <p className="muted">No audit entries match these filters.</p>
      ) : (
        <table className="bo-table">
          <thead>
            <tr>
              <th>When</th>
              <th>Action</th>
              <th>Entity</th>
              <th>User</th>
              <th>Register</th>
              <th>Payload</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id}>
                <td>{r.created_at}</td>
                <td>{r.action}</td>
                <td>
                  {r.entity_type} {r.entity_id}
                </td>
                <td>{r.user_name ?? '—'}</td>
                <td>{r.register_name ?? '—'}</td>
                <td>
                  {r.payload === null ? (
                    '—'
                  ) : (
                    <details>
                      <summary>Payload</summary>
                      <pre>{JSON.stringify(r.payload, null, 2)}</pre>
                    </details>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {hasMore && (
        <button type="button" className="btn btn-utility" disabled={query.isFetching} onClick={() => setPage((p) => p + 1)}>
          {query.isFetching ? 'Loading…' : 'Load more'}
        </button>
      )}
    </section>
  )
}
