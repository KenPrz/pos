'use client'

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { ApiError, api, type ManagedUser } from '../../lib/api'
import { EntityTable } from '../catalog/EntityTable'
import { UserEditor } from './UserEditor'

// Same list-query idiom as CatalogSection's useCatalogList (Task 9): react-query v5
// dropped `onError`, so a settled query error is watched via effect instead.
function useAdminList<T>(key: string, queryFn: () => Promise<T[]>, onUnauthorized: () => void) {
  const query = useQuery({ queryKey: ['admin', key], queryFn })
  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 401) onUnauthorized()
  }, [query.error, onUnauthorized])
  return query
}

/**
 * The Users screen (Task 10, on the component vocabulary since Task 4): one
 * `EntityTable`-on-`DataTable` of every user, with deactivated accounts dimmed
 * (`DataTable`'s `inactive` prop) and reinstate-able rather than removed (users are
 * never deleted — see the account's roles/audit history, which must survive). Roles
 * need the locations list for its "which location" selects, so this loads both the
 * same way ProductsPanel loads categories/modifier-groups alongside products.
 */
export function UsersSection({ onUnauthorized }: { onUnauthorized: () => void }) {
  const users = useAdminList('users', api.users.list, onUnauthorized)
  const locations = useAdminList('locations', api.locations.list, onUnauthorized)
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<ManagedUser | 'new' | null>(null)
  const [error, setError] = useState<string | null>(null)

  const reactivate = useMutation({
    mutationFn: (id: string) => api.users.update(id, { is_active: true }),
    onSuccess: () => {
      setError(null)
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'] })
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not reactivate the user.')
    },
  })

  if (users.isLoading || locations.isLoading) return <p className="type-body-sm text-ink-muted">Loading…</p>
  if (users.isError) return <p className="type-body-sm text-error">Could not load users.</p>

  if (editing !== null) {
    return (
      <UserEditor
        user={editing === 'new' ? null : editing}
        locations={locations.data ?? []}
        onDone={() => setEditing(null)}
        onCancel={() => setEditing(null)}
        onUnauthorized={onUnauthorized}
      />
    )
  }

  // location_name rides on every role assignment already (AdminUserResource /
  // RoleAssignments::describe) — no need to cross-reference the locations list here.
  const rolesLabel = (u: ManagedUser) =>
    u.roles.length === 0 ? '—' : u.roles.map((r) => `${r.location_name} (${r.role})`).join(', ')

  return (
    <>
      <EntityTable<ManagedUser>
        title="Users"
        columns={[
          { header: 'Name', render: (u) => u.name },
          { header: 'Email', render: (u) => u.email ?? '—' },
          { header: 'Admin', render: (u) => (u.is_admin ? 'Yes' : 'No') },
          { header: 'Roles', render: (u) => rolesLabel(u) },
        ]}
        rows={users.data ?? []}
        onEdit={(u) => setEditing(u)}
        onNew={() => setEditing('new')}
        onUnarchive={(u) => reactivate.mutate(u.id)}
        newLabel="New user"
        archivedLabel="INACTIVE"
        unarchiveLabel="Reactivate"
        emptyMessage="No users yet."
      />
      {error && <p className="type-body-sm text-error">{error}</p>}
    </>
  )
}
