'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Location, type ManagedUser } from '../../lib/api'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { DataTable } from '../../components/DataTable'
import { Divider } from '../../components/Divider'
import { FieldRow } from '../../components/FieldRow'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../components/ui/select'

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

// Radix `Select.Item` rejects an empty-string value — see SimpleEditor's identical
// sentinel. "Add location" starts with nothing chosen, so it needs one.
const NONE_LOCATION = '__none__'

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
  // Archive-style confirm (brief's global constraint) — set only when Save would
  // otherwise deactivate; the dialog's Confirm re-plays the exact body already computed.
  const [pendingDeactivate, setPendingDeactivate] = useState<Record<string, unknown> | null>(null)

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

    // Deactivation behind a confirm (brief's global constraint): deactivating an existing
    // user behind a confirm, same as every other is_active:false transition in this app.
    // Never fires on create — there's no prior active row to leave.
    if (body.is_active === false) {
      setPendingDeactivate(body)
      return
    }

    save.mutate(body)
  }

  return (
    <Card>
      <div className="mb-lg flex items-center justify-between gap-md">
        <CardTitle>{user ? 'Edit user' : 'New user'}</CardTitle>
        <Button type="button" variant="tertiary" onClick={onCancel}>
          Back
        </Button>
      </div>

      <form onSubmit={submit} className="flex flex-col gap-md">
        <FieldRow label="Name">
          <Input id="user-name" value={name} onChange={(e) => setName(e.target.value)} />
        </FieldRow>
        <FieldRow label="Email">
          <Input id="user-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        </FieldRow>
        <FieldRow label="Password (leave blank to keep unchanged)">
          <Input id="user-password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </FieldRow>
        <FieldRow label="PIN (leave blank to keep unchanged)">
          <Input id="user-pin" inputMode="numeric" value={pin} onChange={(e) => setPin(e.target.value)} />
        </FieldRow>
        <FieldRow label="Admin">
          <Checkbox checked={isAdmin} onCheckedChange={(checked) => setIsAdmin(Boolean(checked))} />
        </FieldRow>
        {user && (
          <FieldRow label="Active">
            <Checkbox checked={isActive} onCheckedChange={(checked) => setIsActive(Boolean(checked))} />
          </FieldRow>
        )}
        <div>
          <Button type="submit" variant="primary" disabled={save.isPending}>
            {save.isPending ? 'Saving…' : 'Save'}
          </Button>
        </div>
      </form>
      {error && <p className="type-body-sm mt-md text-error">{error}</p>}

      <Divider />

      <CardTitle className="mb-md">Roles</CardTitle>
      <DataTable<RoleRow>
        columns={[
          { key: 'location', header: 'Location', render: (r) => locationName(r.location_id) },
          { key: 'role', header: 'Role', render: (r) => r.role },
          {
            key: 'actions',
            header: 'Actions',
            render: (r) => (
              <Button type="button" variant="ghost" onClick={() => removeRole(r.location_id)}>
                Remove
              </Button>
            ),
          },
        ]}
        rows={roles}
        rowKey={(r) => r.location_id}
        empty={{ title: 'No location roles yet.' }}
      />

      {availableLocations.length > 0 && (
        <div className="mt-md flex flex-wrap items-end gap-md">
          <FieldRow label="Add location">
            <Select
              value={newRoleLocationId || NONE_LOCATION}
              onValueChange={(v) => setNewRoleLocationId(v === NONE_LOCATION ? '' : v)}
            >
              <SelectTrigger id="user-add-role-location">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={NONE_LOCATION}>—</SelectItem>
                {availableLocations.map((l) => (
                  <SelectItem key={l.id} value={l.id}>
                    {l.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </FieldRow>
          <FieldRow label="Add role">
            <Select value={newRoleRole} onValueChange={(v) => setNewRoleRole(v as 'cashier' | 'supervisor')}>
              <SelectTrigger id="user-add-role-role">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="cashier">Cashier</SelectItem>
                <SelectItem value="supervisor">Supervisor</SelectItem>
              </SelectContent>
            </Select>
          </FieldRow>
          <Button type="button" variant="tertiary" onClick={addRole} disabled={newRoleLocationId === ''}>
            Add
          </Button>
        </div>
      )}

      <ConfirmDialog
        open={pendingDeactivate !== null}
        onOpenChange={(open) => {
          if (!open) setPendingDeactivate(null)
        }}
        message={`Deactivate ${name}? They keep their history but can no longer sign in.`}
        confirmLabel="Deactivate"
        destructive
        onConfirm={() => {
          if (!pendingDeactivate) return
          save.mutate(pendingDeactivate)
          setPendingDeactivate(null)
        }}
      />
    </Card>
  )
}
