'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { DataTable } from '../../components/DataTable'
import { FieldRow } from '../../components/FieldRow'
import { Button } from '../../components/ui/button'
import { CardTitle } from '../../components/ui/card'
import { Input } from '../../components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select'
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

// Radix `Select.Item` rejects an empty-string value, so "All" (no entity-type filter)
// needs a sentinel between the trigger and `filters` — translated back to '' at the
// onValueChange boundary; the applied params never see it (same idiom as SimpleEditor).
const ALL_OPTION = '__all__'

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
 * The audit-log viewer, over ListAuditLog. Filters are staged in local state and only
 * take effect on FILTER — applying on every keystroke would refetch per character, which
 * the free-text entity id / user id / action fields would make noisy. Pagination is
 * has_more-driven LOAD MORE, appending pages rather than replacing them, so scrolling
 * back doesn't lose earlier rows.
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
    <div className="flex flex-col gap-lg">
      <CardTitle>Audit log</CardTitle>

      <div className="flex flex-wrap items-end gap-md">
        <div className="w-[200px]">
          <FieldRow label="Entity type">
            <Select
              value={filters.entityType || ALL_OPTION}
              onValueChange={(v) => setFilters((f) => ({ ...f, entityType: v === ALL_OPTION ? '' : v }))}
            >
              <SelectTrigger id="audit-entity-type">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL_OPTION}>All</SelectItem>
                {ENTITY_TYPES.map((t) => (
                  <SelectItem key={t} value={t}>
                    {t}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </FieldRow>
        </div>
        <div className="w-[200px]">
          <FieldRow label="Entity id">
            <Input
              id="audit-entity-id"
              value={filters.entityId}
              onChange={(e) => setFilters((f) => ({ ...f, entityId: e.target.value }))}
            />
          </FieldRow>
        </div>
        <div className="w-[200px]">
          <FieldRow label="User id">
            <Input
              id="audit-user-id"
              value={filters.userId}
              onChange={(e) => setFilters((f) => ({ ...f, userId: e.target.value }))}
            />
          </FieldRow>
        </div>
        <div className="w-[200px]">
          <FieldRow label="Action">
            <Input
              id="audit-action"
              value={filters.action}
              onChange={(e) => setFilters((f) => ({ ...f, action: e.target.value }))}
            />
          </FieldRow>
        </div>
        <div className="w-[180px]">
          <FieldRow label="From">
            <Input
              id="audit-from"
              type="date"
              value={filters.from}
              onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value }))}
            />
          </FieldRow>
        </div>
        <div className="w-[180px]">
          <FieldRow label="To">
            <Input
              id="audit-to"
              type="date"
              value={filters.to}
              onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value }))}
            />
          </FieldRow>
        </div>
        <Button type="button" variant="primary" onClick={applyFilters}>
          Filter
        </Button>
      </div>

      {query.isLoading && page === 1 && <p className="type-body-sm text-ink-muted">Loading…</p>}
      {query.isError && !(query.error instanceof ApiError && query.error.status === 401) && (
        <p className="type-body-sm text-error">Could not load the audit log.</p>
      )}

      {(rows.length > 0 || !query.isLoading) && (
        <DataTable<AuditLogEntry>
          columns={[
            { key: 'created_at', header: 'When', render: (r) => r.created_at },
            { key: 'action', header: 'Action', render: (r) => r.action },
            {
              key: 'entity',
              header: 'Entity',
              render: (r) => (
                <>
                  {r.entity_type} {r.entity_id}
                </>
              ),
            },
            { key: 'user_name', header: 'User', render: (r) => r.user_name ?? '—' },
            { key: 'register_name', header: 'Register', render: (r) => r.register_name ?? '—' },
            {
              key: 'payload',
              header: 'Payload',
              render: (r) =>
                r.payload === null ? (
                  '—'
                ) : (
                  <details>
                    <summary className="cursor-pointer text-primary">Payload</summary>
                    <pre className="type-caption overflow-x-auto">{JSON.stringify(r.payload, null, 2)}</pre>
                  </details>
                ),
            },
          ]}
          rows={rows}
          rowKey={(r) => r.id}
          empty={{ title: 'No audit entries match these filters.' }}
        />
      )}

      {hasMore && (
        <div>
          <Button type="button" variant="ghost" disabled={query.isFetching} onClick={() => setPage((p) => p + 1)}>
            {query.isFetching ? 'Loading…' : 'Load more'}
          </Button>
        </div>
      )}
    </div>
  )
}
