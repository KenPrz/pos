'use client'

import { useState } from 'react'
import { getCurrency } from '../lib/currency'
import { cents, formatMoney } from '../lib/money'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet'
import { cn } from '@/lib/utils'

const fm = (n: number) => formatMoney(cents(n), getCurrency())

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

  // A full-height right Sheet (spec §register), always open while mounted — MenuGrid
  // mounts it per pending pick; any Radix dismissal (overlay tap, Escape) is a cancel.
  return (
    <Sheet open onOpenChange={(open) => { if (!open) onCancel() }}>
      <SheetContent aria-describedby={undefined} className="flex flex-col p-0">
        <div className="min-h-0 flex-1 overflow-y-auto p-lg">
          <SheetTitle>{productName}</SheetTitle>

          {sortedGroups.map((group) => {
            const count = countIn(group.id)
            const atMax = group.max_select != null && count >= group.max_select
            return (
              <div className="mt-lg" key={group.id}>
                <h3 className="type-body-sm font-normal text-ink-muted">
                  {group.name}
                  {group.min_select > 0 && <span> · required</span>}
                </h3>
                <div className="mt-xs flex flex-col">
                  {modifiersByGroup(group.id).map((modifier) => {
                    const times = selected.filter((id) => id === modifier.id).length
                    return (
                      // Deliberately never natively `disabled`: a disabled element doesn't
                      // dispatch click at all, and the "3rd tap is ignored" behavior needs
                      // the tap to still land so `tap()`'s own ceiling guard can no-op it.
                      // The at-max dimming is a purely visual cue instead.
                      <button
                        key={modifier.id}
                        type="button"
                        data-state={times > 0 ? 'selected' : atMax ? 'at-max' : 'idle'}
                        className={cn(
                          'flex min-h-[56px] w-full items-center gap-sm rounded-none border-b border-hairline px-sm text-left text-ink',
                          'outline-none focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary',
                          times > 0 && 'border-l-4 border-l-primary bg-surface-1',
                          atMax && times === 0 && 'opacity-50'
                        )}
                        onClick={() => tap(modifier)}
                      >
                        <span className="type-body-lg min-w-0 flex-1">{modifier.name}</span>
                        {modifier.price_delta_cents !== 0 && (
                          <span className="type-body-sm type-money shrink-0 text-ink-muted"> {fm(modifier.price_delta_cents)}</span>
                        )}
                        {times > 1 && <span className="type-body-sm shrink-0 text-ink-muted"> ×{times}</span>}
                      </button>
                    )
                  })}
                </div>
              </div>
            )
          })}
        </div>

        {/* Running delta pinned by the ADD action (spec §register), same labels. */}
        <div className="flex shrink-0 items-center justify-between gap-md border-t border-hairline p-lg">
          <span className="type-body-lg type-money">
            {total !== 0 && (total > 0 ? '+' : '')}
            {fm(total)}
          </span>
          <div className="flex gap-sm">
            <Button type="button" variant="secondary" size="lg" onClick={onCancel}>
              Cancel
            </Button>
            <Button type="button" size="lg" disabled={!allGroupsSatisfied} onClick={() => onConfirm(selected)}>
              Add {total !== 0 && `— ${fm(total)}`}
            </Button>
          </div>
        </div>
      </SheetContent>
    </Sheet>
  )
}
