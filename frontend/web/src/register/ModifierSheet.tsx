'use client'

import { useState } from 'react'
import { cents, formatMoney } from '../lib/money'

const CURRENCY = 'USD'
const fm = (n: number) => formatMoney(cents(n), CURRENCY)

// A thinner shape than the wire ModifierGroup/Modifier types (api.ts) — deliberately:
// this component only ever needs id/name/group_id/min_select/max_select/price_delta_cents,
// and keeping it structural lets the test file (and MenuGrid) pass plain object literals
// without importing the wire types. ModifierGroup carries no `position` field on the
// wire (see api.ts) — sort order here is required-first only, ties broken by array order.
type ModifierGroupLike = { id: string; name: string; min_select: number; max_select: number | null }
type ModifierLike = { id: string; group_id: string; name: string; price_delta_cents: number; position: number }

export function ModifierSheet({
  productName,
  groups,
  modifiers,
  onConfirm,
  onCancel,
}: {
  productName: string
  groups: ModifierGroupLike[]
  modifiers: ModifierLike[]
  onConfirm: (modifierIds: string[]) => void
  onCancel: () => void
}) {
  // Selections in tap order, repeats included — "double bacon" is two entries of the
  // same modifier id, not a qty field. Confirmed straight through to onConfirm.
  const [selected, setSelected] = useState<string[]>([])

  // Required groups (min_select > 0) sort first — Array.prototype.sort is stable, so
  // ties (same required-ness) keep the caller's original order. There is no `position`
  // to sort by within a tier; the group array's own order is the tiebreak.
  const sortedGroups = [...groups].sort((a, b) => Number(b.min_select > 0) - Number(a.min_select > 0))

  const modifiersByGroup = (groupId: string) =>
    modifiers.filter((m) => m.group_id === groupId).sort((a, b) => a.position - b.position)

  const countIn = (groupId: string) => selected.filter((id) => modifiers.find((m) => m.id === id)?.group_id === groupId).length

  const tap = (modifier: ModifierLike) => {
    const group = groups.find((g) => g.id === modifier.group_id)
    const count = countIn(modifier.group_id)
    if (group?.max_select != null && count >= group.max_select) return // at the group's ceiling — the tap is a no-op
    setSelected((prev) => [...prev, modifier.id])
  }

  const allGroupsSatisfied = groups.every((g) => {
    const count = countIn(g.id)
    return count >= g.min_select && (g.max_select == null || count <= g.max_select)
  })

  const total = selected.reduce((sum, id) => sum + (modifiers.find((m) => m.id === id)?.price_delta_cents ?? 0), 0)

  return (
    <div className="modifier-sheet-backdrop">
      <section className="form-panel modifier-sheet">
        <h2>{productName}</h2>

        {sortedGroups.map((group) => {
          const count = countIn(group.id)
          const atMax = group.max_select != null && count >= group.max_select
          return (
            <div className="modifier-group" key={group.id}>
              <h3 className="modifier-group-label">
                {group.name}
                {group.min_select > 0 && <span className="modifier-required"> · required</span>}
              </h3>
              <div className="modifier-chips">
                {modifiersByGroup(group.id).map((modifier) => {
                  const times = selected.filter((id) => id === modifier.id).length
                  return (
                    // Deliberately never natively `disabled`: a disabled element doesn't
                    // dispatch click at all, and the "3rd tap is ignored" behavior needs
                    // the tap to still land so `tap()`'s own ceiling guard can no-op it.
                    // `.at-max` is a purely visual (CSS) cue instead.
                    <button
                      key={modifier.id}
                      type="button"
                      className={`chip modifier-chip${times > 0 ? ' selected' : ''}${atMax && times === 0 ? ' at-max' : ''}`}
                      onClick={() => tap(modifier)}
                    >
                      {modifier.name}
                      {modifier.price_delta_cents !== 0 && <span className="chip-delta"> {fm(modifier.price_delta_cents)}</span>}
                      {times > 1 && <span className="chip-count"> ×{times}</span>}
                    </button>
                  )
                })}
              </div>
            </div>
          )
        })}

        <div className="modifier-sheet-footer">
          <span className="modifier-sheet-total">
            {total !== 0 && (total > 0 ? '+' : '')}
            {fm(total)}
          </span>
          <div className="btn-row">
            <button type="button" className="btn btn-secondary" onClick={onCancel}>
              Cancel
            </button>
            <button type="button" className="btn btn-submit" disabled={!allGroupsSatisfied} onClick={() => onConfirm(selected)}>
              Add {total !== 0 && `— ${fm(total)}`}
            </button>
          </div>
        </div>
      </section>
    </div>
  )
}
