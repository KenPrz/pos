'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { ApiError, api, type Location, type PaymentMethod, type PaymentMethodGroup } from '../../lib/api'
import { EmptyState } from '../../components/EmptyState'
import { StatusPill } from '../../components/StatusPill'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { GroupEditor, driverLabel } from './GroupEditor'
import { MethodEditor } from './MethodEditor'

type Editing =
  | { kind: 'group'; group: PaymentMethodGroup | null }
  | { kind: 'method'; group: PaymentMethodGroup; method: PaymentMethod | null }
  | null

/**
 * The location's tender taxonomy: groups (each naming one driver) with their methods
 * nested beneath. Scoped by the sidebar location switcher — payment methods are
 * per-location data, and both codes are unique per location.
 *
 * Archive, never delete: an archived row stays visible and dimmed with an ARCHIVED pill,
 * the same mechanics the catalog uses.
 */
export function PaymentMethodsSection({
  location,
  onUnauthorized,
}: {
  location: Location | null
  onUnauthorized: () => void
}) {
  const [editing, setEditing] = useState<Editing>(null)

  const groups = useQuery({
    queryKey: ['admin', 'payment-method-groups', location?.id ?? null],
    queryFn: () => api.paymentMethodGroups.list(location!.id),
    enabled: location !== null,
  })

  useEffect(() => {
    if (groups.error instanceof ApiError && groups.error.status === 401) onUnauthorized()
  }, [groups.error, onUnauthorized])

  if (location === null) return <p className="type-body-sm text-ink-muted">Pick a location first.</p>
  if (groups.isLoading) return <p className="type-body-sm text-ink-muted">Loading…</p>
  if (groups.isError) return <p className="type-body-sm text-error">Could not load payment methods.</p>

  if (editing?.kind === 'group') {
    return (
      <GroupEditor
        group={editing.group}
        location={location}
        onDone={() => setEditing(null)}
        onCancel={() => setEditing(null)}
        onUnauthorized={onUnauthorized}
      />
    )
  }

  if (editing?.kind === 'method') {
    return (
      <MethodEditor
        method={editing.method}
        group={editing.group}
        location={location}
        onDone={() => setEditing(null)}
        onCancel={() => setEditing(null)}
        onUnauthorized={onUnauthorized}
      />
    )
  }

  const rows = groups.data ?? []

  return (
    <div className="flex flex-col gap-lg">
      <div className="flex items-center justify-between">
        <CardTitle>Payment methods — {location.name}</CardTitle>
        <Button onClick={() => setEditing({ kind: 'group', group: null })}>New group</Button>
      </div>

      {rows.length === 0 && (
        <EmptyState
          title="No payment methods yet"
          description="This location cannot take payment until it has at least one method. Start with a group."
        />
      )}

      {rows.map((group) => (
        <Card key={group.id} className={group.is_active ? undefined : 'opacity-60'}>
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-sm">
              <CardTitle>{group.name}</CardTitle>
              <span className="type-body-sm text-ink-muted">
                {group.code} · {driverLabel(group.driver)}
              </span>
              {!group.is_active && <StatusPill tone="neutral">ARCHIVED</StatusPill>}
            </div>
            <div className="flex gap-sm">
              <Button variant="tertiary" onClick={() => setEditing({ kind: 'group', group })}>
                Edit
              </Button>
              <Button variant="tertiary" onClick={() => setEditing({ kind: 'method', group, method: null })}>
                New method
              </Button>
            </div>
          </div>

          <ul className="flex flex-col gap-xs pt-md">
            {(group.methods ?? []).map((method) => (
              <li key={method.id} className="flex items-center justify-between border-t border-hairline pt-xs">
                {/* StatusPill takes only `tone` and children — spacing goes on a wrapper. */}
                <span className={method.is_active ? undefined : 'opacity-60'}>
                  {method.name} <span className="type-body-sm text-ink-muted">{method.code}</span>
                  {!method.is_active && (
                    <span className="ml-sm">
                      <StatusPill tone="neutral">ARCHIVED</StatusPill>
                    </span>
                  )}
                </span>
                <Button variant="ghost" onClick={() => setEditing({ kind: 'method', group, method })}>
                  Edit
                </Button>
              </li>
            ))}
            {(group.methods ?? []).length === 0 && (
              <li className="type-body-sm text-ink-muted">No methods in this group yet.</li>
            )}
          </ul>
        </Card>
      ))}
    </div>
  )
}
