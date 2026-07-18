'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Modifier, type ModifierGroup } from '../../lib/api'
import { parseCents } from '../../lib/money'
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
    const body: Record<string, unknown> = {}
    const put = (key: string, value: unknown, original: unknown) => {
      if (modifier === null || value !== original) body[key] = value
    }
    if (modifier === null) body.group_id = groupId
    put('name', name, modifier?.name)
    put('price_delta_cents', priceDelta, modifier?.price_delta_cents)
    put('position', Number(position), modifier?.position)
    if (modifier) put('is_active', isActive, modifier.is_active)
    save.mutate(body)
  }

  return (
    <form className="inline-reason" onSubmit={submit} style={{ flexWrap: 'wrap' }}>
      <label htmlFor="modifier-name">
        Name
        <input id="modifier-name" value={name} onChange={(e) => setName(e.target.value)} />
      </label>
      <MoneyField id="modifier-price" label="Price delta" value={priceInput} onChange={setPriceInput} />
      <label htmlFor="modifier-position">
        Position
        <input id="modifier-position" inputMode="numeric" value={position} onChange={(e) => setPosition(e.target.value)} />
      </label>
      {modifier && (
        <label htmlFor="modifier-active">
          Active
          <input id="modifier-active" type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
        </label>
      )}
      <button type="submit" className="btn btn-submit" disabled={save.isPending}>
        {save.isPending ? 'Saving…' : 'Save'}
      </button>
      <button type="button" className="btn btn-secondary" onClick={onCancel}>
        Cancel
      </button>
      {error && <p className="error">{error}</p>}
    </form>
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
    const min = Number(minSelect)
    const max = maxSelect === '' ? null : Number(maxSelect)
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
    <section className="form-panel">
      <header className="row">
        <h2>{group ? 'Edit modifier group' : 'New modifier group'}</h2>
        <button type="button" className="btn btn-secondary" onClick={onCancel}>
          Back
        </button>
      </header>

      <form onSubmit={submit}>
        <label htmlFor="group-name">
          Name
          <input id="group-name" value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <label htmlFor="group-min-select">
          Min select
          <input id="group-min-select" inputMode="numeric" value={minSelect} onChange={(e) => setMinSelect(e.target.value)} />
        </label>
        <label htmlFor="group-max-select">
          Max select (blank = unlimited)
          <input id="group-max-select" inputMode="numeric" value={maxSelect} onChange={(e) => setMaxSelect(e.target.value)} />
        </label>
        <button type="submit" className="btn btn-submit" disabled={save.isPending}>
          {save.isPending ? 'Saving…' : 'Save'}
        </button>
      </form>
      {error && <p className="error">{error}</p>}

      <hr className="dotted-divider" />

      {group === null ? (
        <>
          <h3>Modifiers</h3>
          <p className="muted">Save the group first to add modifiers.</p>
        </>
      ) : (
        <>
          {editingModifier !== null ? (
            <>
              <h3>Modifiers</h3>
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
              emptyMessage="No modifiers in this group yet."
            />
          )}
        </>
      )}
    </section>
  )
}
