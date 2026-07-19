'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Modifier, type ModifierGroup } from '../../lib/api'
import { parseCents } from '../../lib/money'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { FieldRow } from '../../components/FieldRow'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { MoneyField } from './MoneyField'
import { EntityTable } from './EntityTable'

/** Signed cents ("no cheese, -50c" is a real modifier) — parseCentsOrNull rejects
 * negatives, so this field needs the throwing parser wrapped locally instead. */
function parseSignedCentsOrNull(input: string): number | null {
  try {
    return parseCents(input)
  } catch {
    return null
  }
}

function fm(cents: number): string {
  return (cents / 100).toFixed(2)
}

/** Table display for a signed delta — "-$0.50", not fm's raw "$-0.50". */
function fmSigned(cents: number): string {
  return cents < 0 ? `-$${fm(-cents)}` : `$${fm(cents)}`
}

/** Inline create/edit form for one modifier — nested under its group, never a top-level
 * catalog tab (a modifier only makes sense in the context of the group it belongs to). */
function ModifierForm({
  groupId,
  modifier,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  groupId: string
  modifier: Modifier | null
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [name, setName] = useState(modifier?.name ?? '')
  const [priceInput, setPriceInput] = useState(modifier ? fm(modifier.price_delta_cents) : '0.00')
  const [position, setPosition] = useState(String(modifier?.position ?? 0))
  const [isActive, setIsActive] = useState(modifier?.is_active ?? true)
  const [error, setError] = useState<string | null>(null)
  // Archive behind a confirm (brief's global constraint) — set only when Save would
  // otherwise archive; the dialog's Confirm re-plays the exact body already computed.
  const [pendingArchive, setPendingArchive] = useState<Record<string, unknown> | null>(null)

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      modifier ? api.modifiers.update(modifier.id, body) : api.modifiers.create(body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'modifiers'] })
      onDone()
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not save the modifier.')
    },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    const priceDelta = parseSignedCentsOrNull(priceInput)
    if (priceDelta === null) {
      setError('Enter a valid amount (e.g. -0.50).')
      return
    }
    // Blank must fail validation, not silently coerce to 0 (`Number('') === 0`) —
    // same failure class as the money fields.
    if (position.trim() === '') {
      setError('Enter a position (e.g. 0).')
      return
    }
    const positionValue = Number(position)
    if (!Number.isFinite(positionValue)) {
      setError('Enter a valid position (e.g. 0).')
      return
    }
    const body: Record<string, unknown> = {}
    const put = (key: string, value: unknown, original: unknown) => {
      if (modifier === null || value !== original) body[key] = value
    }
    if (modifier === null) body.group_id = groupId
    put('name', name, modifier?.name)
    put('price_delta_cents', priceDelta, modifier?.price_delta_cents)
    put('position', positionValue, modifier?.position)
    if (modifier) put('is_active', isActive, modifier.is_active)

    // Archive behind a confirm (brief's global constraint) — unchecking Active and
    // hitting Save must not silently archive. UNARCHIVE (the table action) needs none.
    if (body.is_active === false) {
      setPendingArchive(body)
      return
    }
    save.mutate(body)
  }

  return (
    <div className="flex flex-col gap-md">
      <form onSubmit={submit} className="flex flex-col gap-md">
        <div className="flex flex-wrap items-end gap-md">
          <FieldRow label="Name">
            <Input value={name} onChange={(e) => setName(e.target.value)} />
          </FieldRow>
          <MoneyField id="modifier-price" label="Price delta" value={priceInput} onChange={setPriceInput} />
          <FieldRow label="Position">
            <Input inputMode="numeric" value={position} onChange={(e) => setPosition(e.target.value)} />
          </FieldRow>
          {modifier && (
            <FieldRow label="Active">
              <Checkbox checked={isActive} onCheckedChange={(checked) => setIsActive(Boolean(checked))} />
            </FieldRow>
          )}
        </div>
        <div className="flex items-center gap-xs">
          <Button type="submit" variant="primary" disabled={save.isPending}>
            {save.isPending ? 'Saving…' : 'Save'}
          </Button>
          <Button type="button" variant="tertiary" onClick={onCancel}>
            Cancel
          </Button>
        </div>
      </form>
      {error && <p className="type-body-sm text-error">{error}</p>}

      <ConfirmDialog
        open={pendingArchive !== null}
        onOpenChange={(open) => {
          if (!open) setPendingArchive(null)
        }}
        message={`Archive ${name}? It leaves the register catalog but stays in history.`}
        confirmLabel="Archive"
        destructive
        onConfirm={() => {
          if (!pendingArchive) return
          save.mutate(pendingArchive)
          setPendingArchive(null)
        }}
      />
    </div>
  )
}

/**
 * Group fields (name, min/max select) plus its nested modifiers — the brief lists no
 * separate "Modifiers" catalog tab, so modifier CRUD lives here, scoped to the group
 * being edited. Unlike ProductEditor's two independent actions, this one genuinely
 * requires the group to already exist (a modifier's group_id is required and immutable),
 * so the nested table only appears once `group` is non-null.
 */
export function ModifierGroupEditor({
  group,
  modifiers,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  group: ModifierGroup | null
  modifiers: Modifier[]
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [name, setName] = useState(group?.name ?? '')
  const [minSelect, setMinSelect] = useState(String(group?.min_select ?? 0))
  const [maxSelect, setMaxSelect] = useState(group?.max_select != null ? String(group.max_select) : '')
  const [error, setError] = useState<string | null>(null)
  const [editingModifier, setEditingModifier] = useState<Modifier | 'new' | null>(null)

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      group ? api.modifierGroups.update(group.id, body) : api.modifierGroups.create(body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'modifier-groups'] })
      onDone()
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not save the modifier group.')
    },
  })

  const unarchiveModifier = useMutation({
    mutationFn: (modifierId: string) => api.modifiers.update(modifierId, { is_active: true }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'modifiers'] }),
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not unarchive the modifier.')
    },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)

    // Blank must fail validation, not silently coerce to 0 (`Number('') === 0`).
    // Max select's blank IS meaningful ("unlimited" — the field label says so), so only
    // min select gets the strict blank-is-an-error treatment; both still guard against
    // non-numeric garbage (`Number('abc')` is NaN, which JSON.stringify would otherwise
    // silently turn into `null` on the wire — a false "unlimited" for a mistyped max).
    if (minSelect.trim() === '') {
      setError('Enter a min select value (e.g. 0).')
      return
    }
    const min = Number(minSelect)
    if (!Number.isFinite(min)) {
      setError('Enter a valid min select value (e.g. 0).')
      return
    }
    const max = maxSelect === '' ? null : Number(maxSelect)
    if (max !== null && !Number.isFinite(max)) {
      setError('Enter a valid max select value, or leave it blank for unlimited.')
      return
    }
    if (max !== null && max < min) {
      setError('Max select must be >= min select.')
      return
    }
    const body: Record<string, unknown> = {}
    const put = (key: string, value: unknown, original: unknown) => {
      if (group === null || value !== original) body[key] = value
    }
    put('name', name, group?.name)
    put('min_select', min, group?.min_select)
    put('max_select', max, group?.max_select)
    save.mutate(body)
  }

  return (
    <Card>
      <div className="mb-lg flex items-center justify-between gap-md">
        <CardTitle>{group ? 'Edit modifier group' : 'New modifier group'}</CardTitle>
        <Button type="button" variant="tertiary" onClick={onCancel}>
          Back
        </Button>
      </div>

      <form onSubmit={submit} className="flex flex-col gap-md">
        <FieldRow label="Name">
          <Input value={name} onChange={(e) => setName(e.target.value)} />
        </FieldRow>
        <FieldRow label="Min select">
          <Input inputMode="numeric" value={minSelect} onChange={(e) => setMinSelect(e.target.value)} />
        </FieldRow>
        <FieldRow label="Max select (blank = unlimited)">
          <Input inputMode="numeric" value={maxSelect} onChange={(e) => setMaxSelect(e.target.value)} />
        </FieldRow>
        <div>
          <Button type="submit" variant="primary" disabled={save.isPending}>
            {save.isPending ? 'Saving…' : 'Save'}
          </Button>
        </div>
      </form>
      {error && <p className="type-body-sm mt-md text-error">{error}</p>}

      <hr className="my-lg border-t border-hairline" />

      {group === null ? (
        <>
          <CardTitle className="mb-md">Modifiers</CardTitle>
          <p className="type-body-sm text-ink-muted">Save the group first to add modifiers.</p>
        </>
      ) : (
        <>
          {editingModifier !== null ? (
            <>
              <CardTitle className="mb-md">Modifiers</CardTitle>
              <ModifierForm
                groupId={group.id}
                modifier={editingModifier === 'new' ? null : editingModifier}
                onDone={() => setEditingModifier(null)}
                onCancel={() => setEditingModifier(null)}
                onUnauthorized={onUnauthorized}
              />
            </>
          ) : (
            <EntityTable<Modifier>
              title="Modifiers"
              columns={[
                { header: 'Name', render: (m) => m.name },
                { header: 'Price delta', render: (m) => fmSigned(m.price_delta_cents) },
                { header: 'Position', render: (m) => String(m.position) },
              ]}
              rows={modifiers}
              onEdit={(m) => setEditingModifier(m)}
              onNew={() => setEditingModifier('new')}
              onUnarchive={(m) => unarchiveModifier.mutate(m.id)}
              newLabel="New modifier"
              // Downgraded from the default `primary` (ProductEditor's "Save modifier
              // groups" precedent) — the group's own Save above is this screen's one
              // primary action; a nested table can't have a second.
              newButtonVariant="tertiary"
              emptyMessage="No modifiers in this group yet."
            />
          )}
        </>
      )}
    </Card>
  )
}
