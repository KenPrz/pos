'use client'

import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ApiError, api, type Role } from '../../lib/api'
import { StatusPill } from '../../components/StatusPill'
import { EntityTable } from '../catalog/EntityTable'
import { RoleEditor } from './RoleEditor'

// Same list-query idiom as UsersSection's/PlacesSection's useAdminList (Task 9/10).
function useAdminList<T>(key: string, queryFn: () => Promise<T[]>, onUnauthorized: () => void) {
  const query = useQuery({ queryKey: ['admin', key], queryFn })
  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 401) onUnauthorized()
  }, [query.error, onUnauthorized])
  return query
}

/**
 * The Roles tab (Task 10, RBAC v2): one `EntityTable` of every role template — name,
 * how many permissions it carries, how many users hold it, and whether it's a system
 * template (`cashier`/`supervisor`, pinned name, still-editable permissions). Roles are
 * deleted outright rather than archived (`role_templates` has no `is_active` column;
 * `RoleEditor`'s own delete flow gates on that), so this list carries no unarchive
 * mechanics at all — unlike every other `EntityTable` caller in this app.
 */
export function RolesPanel({ onUnauthorized }: { onUnauthorized: () => void }) {
  const roles = useAdminList('roles', api.roles.list, onUnauthorized)
  const [editing, setEditing] = useState<Role | 'new' | null>(null)

  if (roles.isLoading) return <p className="type-body-sm text-ink-muted">Loading…</p>
  if (roles.isError) return <p className="type-body-sm text-error">Could not load roles.</p>

  if (editing !== null) {
    return (
      <RoleEditor
        role={editing === 'new' ? null : editing}
        onDone={() => setEditing(null)}
        onCancel={() => setEditing(null)}
        onUnauthorized={onUnauthorized}
      />
    )
  }

  return (
    <EntityTable<Role>
      title="Roles"
      columns={[
        { header: 'Name', render: (r) => r.name },
        { header: 'Permissions', render: (r) => String(r.permissions.length) },
        { header: 'Assigned users', render: (r) => String(r.assigned_users) },
        {
          header: 'System',
          render: (r) => (r.is_system ? <StatusPill tone="info">SYSTEM</StatusPill> : '—'),
        },
      ]}
      rows={roles.data ?? []}
      onEdit={(r) => setEditing(r)}
      onNew={() => setEditing('new')}
      newLabel="New role"
      emptyMessage="No roles yet."
    />
  )
}
