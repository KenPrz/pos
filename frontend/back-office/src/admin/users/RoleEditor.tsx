'use client'

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Role } from '../../lib/api'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { FieldRow } from '../../components/FieldRow'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'

/** `permissions[]` sorted so an unrelated diff (e.g. name only) never reorders it into
 * a spurious change — same reasoning as UserEditor's `toRoleRows`. */
function sortedPermissions(permissions: Iterable<string>): string[] {
  return Array.from(permissions).sort()
}

function samePermissions(a: string[], b: string[]): boolean {
  return a.length === b.length && a.every((p, i) => p === b[i])
}

/**
 * A role template's name + its permission set, plus (custom templates only) delete.
 * `is_system` locks the name (`cashier`/`supervisor` — every seed and doc assumes
 * these exact names exist, `RoleTemplateIsSystem` is the server's hard backstop) but
 * its permissions stay editable either way. Save is create-vs-update on `role === null`,
 * same idiom as every other editor in this app; permissions only ride the PATCH body
 * when the (sorted) set actually changed.
 */
export function RoleEditor({
  role,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  role: Role | null
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [name, setName] = useState(role?.name ?? '')
  const [permissions, setPermissions] = useState<Set<string>>(new Set(role?.permissions ?? []))
  const [error, setError] = useState<string | null>(null)
  const [pendingDelete, setPendingDelete] = useState(false)

  const groups = useQuery({ queryKey: ['admin', 'permission-groups'], queryFn: api.roles.permissionGroups })

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) => (role ? api.roles.update(role.id, body) : api.roles.create(body)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'roles'] })
      onDone()
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not save the role.')
    },
  })

  const deleteRole = useMutation({
    mutationFn: () => api.roles.deleteRole(role?.id ?? ''),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'roles'] })
      onDone()
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      // Surfaces the server's message verbatim — this is how `role_template_in_use`
      // ("Unassign this role everywhere first.") reaches the screen, same idiom as
      // every other editor's onError.
      setError(err instanceof ApiError ? err.message : 'Could not delete the role.')
    },
  })

  const togglePermission = (permission: string) => {
    setPermissions((prev) => {
      const next = new Set(prev)
      if (next.has(permission)) next.delete(permission)
      else next.add(permission)
      return next
    })
  }

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)

    const permissionList = sortedPermissions(permissions)
    const body: Record<string, unknown> = {}

    if (role === null) {
      body.name = name
      body.permissions = permissionList
    } else {
      if (name !== role.name) body.name = name
      if (!samePermissions(permissionList, sortedPermissions(role.permissions))) body.permissions = permissionList
    }

    save.mutate(body)
  }

  return (
    <Card>
      <div className="mb-lg flex items-center justify-between gap-md">
        <CardTitle>{role ? 'Edit role' : 'New role'}</CardTitle>
        <Button type="button" variant="tertiary" onClick={onCancel}>
          Back
        </Button>
      </div>

      <form onSubmit={submit} className="flex flex-col gap-md">
        <FieldRow label="Name">
          <Input
            id="role-name"
            value={name}
            disabled={role?.is_system ?? false}
            onChange={(e) => setName(e.target.value)}
          />
        </FieldRow>
        {role?.is_system && (
          <p className="type-caption text-ink-muted">System roles keep their name; their permissions still are editable.</p>
        )}

        {groups.isLoading && <p className="type-body-sm text-ink-muted">Loading permissions…</p>}
        {groups.isError && <p className="type-body-sm text-error">Could not load the permission catalog.</p>}
        {groups.data?.map((group) => (
          <fieldset key={group.label} className="flex flex-col gap-xs">
            <legend className="type-body-sm font-semibold text-ink">{group.label}</legend>
            {group.permissions.map((permission) => (
              <FieldRow key={permission} label={permission}>
                <Checkbox
                  checked={permissions.has(permission)}
                  onCheckedChange={() => togglePermission(permission)}
                />
              </FieldRow>
            ))}
          </fieldset>
        ))}

        <div className="flex items-center gap-xs">
          <Button type="submit" variant="primary" disabled={save.isPending}>
            {save.isPending ? 'Saving…' : 'Save'}
          </Button>
          {role && !role.is_system && (
            <Button type="button" variant="danger" onClick={() => setPendingDelete(true)}>
              Delete
            </Button>
          )}
        </div>
      </form>
      {error && <p className="type-body-sm mt-md text-error">{error}</p>}

      <ConfirmDialog
        open={pendingDelete}
        onOpenChange={setPendingDelete}
        message="Delete this role? It must be unassigned everywhere."
        confirmLabel="Delete"
        destructive
        onConfirm={() => {
          setPendingDelete(false)
          deleteRole.mutate()
        }}
      />
    </Card>
  )
}
