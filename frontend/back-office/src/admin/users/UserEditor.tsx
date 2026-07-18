'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Location, type ManagedUser } from '../../lib/api'

type RoleRow = { location_id: string; role: 'cashier' | 'supervisor' }

/** `{location_id, location_name, role}[]` -> `{location_id, role}[]`, sorted by location
 * so an unrelated field's edit never reorders this array into a spurious diff. */
function toRoleRows(roles: ManagedUser['roles']): RoleRow[] {
  return roles
    .map((r) => ({ location_id: r.location_id, role: r.role }))
    .sort((a, b) => a.location_id.localeCompare(b.location_id))
}

function sameRoles(a: RoleRow[], b: RoleRow[]): boolean {
  return a.length === b.length && a.every((row, i) => row.location_id === b[i].location_id && row.role === b[i].role)
}

/**
 * Name/email/password/PIN + per-location roles + is_admin/is_active. PIN and password
 * are set-only (CreateUserRequest/UpdateUserRequest both treat them as write-only
 * columns — there's nothing to read back, `AdminUserResource` doesn't carry either), so
 * both fields always start blank and are only sent when the staff member actually types
 * something; leaving them blank means "no change" on an edit and "no password/PIN" on a
 * create.
 *
 * Roles are a full-set replace server-side (`RoleAssignments::sync`), but this editor
 * still diffs before sending: if the role rows come back byte-identical to what the user
 * started with, `roles` is left out of the PATCH body entirely, same discipline as every
 * other field here.
 */
export function UserEditor({
  user,
  locations,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  user: ManagedUser | null
  locations: Location[]
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [name, setName] = useState(user?.name ?? '')
  const [email, setEmail] = useState(user?.email ?? '')
  const [password, setPassword] = useState('')
  const [pin, setPin] = useState('')
  const [isAdmin, setIsAdmin] = useState(user?.is_admin ?? false)
  const [isActive, setIsActive] = useState(user?.is_active ?? true)
  const initialRoles = user ? toRoleRows(user.roles) : []
  const [roles, setRoles] = useState<RoleRow[]>(initialRoles)
  const [newRoleLocationId, setNewRoleLocationId] = useState('')
  const [newRoleRole, setNewRoleRole] = useState<'cashier' | 'supervisor'>('cashier')
  const [error, setError] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) => (user ? api.users.update(user.id, body) : api.users.create(body)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'] })
      onDone()
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      // Renders the server message as-is — this is how the self-lockout 422
      // ("You cannot remove your own admin access...") and any other domain refusal
      // reach the screen, same idiom as every other editor's onError.
      setError(err instanceof ApiError ? err.message : 'Could not save the user.')
    },
  })

  const locationName = (id: string) => locations.find((l) => l.id === id)?.name ?? '—'
  const availableLocations = locations.filter((l) => !roles.some((r) => r.location_id === l.id))

  const addRole = () => {
    if (newRoleLocationId === '') return
    setRoles((rs) => [...rs, { location_id: newRoleLocationId, role: newRoleRole }].sort((a, b) => a.location_id.localeCompare(b.location_id)))
    setNewRoleLocationId('')
    setNewRoleRole('cashier')
  }

  const removeRole = (locationId: string) => {
    setRoles((rs) => rs.filter((r) => r.location_id !== locationId))
  }

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)

    // Mirrors the server's email-or-pin CHECK (CreateUserRequest: `required_without`
    // each way) as UX — a 400 round trip for a case the form already knows about would
    // just be a slower version of the same message.
    if (user === null && email.trim() === '' && pin.trim() === '') {
      setError('Enter an email or a PIN.')
      return
    }

    const body: Record<string, unknown> = {}
    const put = (key: string, value: unknown, original: unknown) => {
      if (user === null || value !== original) body[key] = value
    }
    put('name', name, user?.name)
    put('email', email.trim() === '' ? null : email, user?.email)
    put('is_admin', isAdmin, user?.is_admin)
    if (user) put('is_active', isActive, user.is_active)

    if (password.trim() !== '') body.password = password
    if (pin.trim() !== '') body.pin = pin

    if (user === null ? roles.length > 0 : !sameRoles(roles, initialRoles)) {
      body.roles = roles.map((r) => ({ location_id: r.location_id, role: r.role }))
    }

    // Archive-style confirm (brief's global constraint): deactivating an existing user
    // behind a confirm, same as every other is_active:false transition in this app.
    // Never fires on create — there's no prior active row to leave.
    if (body.is_active === false && !window.confirm(`Deactivate ${name}? They keep their history but can no longer sign in.`)) {
      return
    }

    save.mutate(body)
  }

  return (
    <section className="form-panel">
      <header className="row">
        <h2>{user ? 'Edit user' : 'New user'}</h2>
        <button type="button" className="btn btn-secondary" onClick={onCancel}>
          Back
        </button>
      </header>

      <form onSubmit={submit}>
        <label htmlFor="user-name">
          Name
          <input id="user-name" value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <label htmlFor="user-email">
          Email
          <input id="user-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        </label>
        <label htmlFor="user-password">
          Password (leave blank to keep unchanged)
          <input id="user-password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        <label htmlFor="user-pin">
          PIN (leave blank to keep unchanged)
          <input id="user-pin" inputMode="numeric" value={pin} onChange={(e) => setPin(e.target.value)} />
        </label>
        <label htmlFor="user-admin">
          Admin
          <input id="user-admin" type="checkbox" checked={isAdmin} onChange={(e) => setIsAdmin(e.target.checked)} />
        </label>
        {user && (
          <label htmlFor="user-active">
            Active
            <input id="user-active" type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          </label>
        )}
        <button type="submit" className="btn btn-submit" disabled={save.isPending}>
          {save.isPending ? 'Saving…' : 'Save'}
        </button>
      </form>
      {error && <p className="error">{error}</p>}

      <hr className="dotted-divider" />

      <h3>Roles</h3>
      {roles.length === 0 ? (
        <p className="muted">No location roles yet.</p>
      ) : (
        <table className="bo-table">
          <thead>
            <tr>
              <th>Location</th>
              <th>Role</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {roles.map((r) => (
              <tr key={r.location_id}>
                <td>{locationName(r.location_id)}</td>
                <td>{r.role}</td>
                <td>
                  <button type="button" className="btn btn-secondary btn-chip" onClick={() => removeRole(r.location_id)}>
                    Remove
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {availableLocations.length > 0 && (
        <div className="inline-reason">
          <label htmlFor="user-add-role-location">
            Add location
            <select id="user-add-role-location" value={newRoleLocationId} onChange={(e) => setNewRoleLocationId(e.target.value)}>
              <option value="">—</option>
              {availableLocations.map((l) => (
                <option key={l.id} value={l.id}>
                  {l.name}
                </option>
              ))}
            </select>
          </label>
          <label htmlFor="user-add-role-role">
            Add role
            <select id="user-add-role-role" value={newRoleRole} onChange={(e) => setNewRoleRole(e.target.value as 'cashier' | 'supervisor')}>
              <option value="cashier">Cashier</option>
              <option value="supervisor">Supervisor</option>
            </select>
          </label>
          <button type="button" className="btn btn-utility" onClick={addRole} disabled={newRoleLocationId === ''}>
            Add
          </button>
        </div>
      )}
    </section>
  )
}
