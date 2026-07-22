'use client'

import type { Order } from '../lib/api'
import { getCurrency } from '../lib/currency'
import { allocate, cents, formatMoney } from '../lib/money'
import { ActionZone } from '@/components/ActionZone'
import { PillStrip, type PillStripItem } from '@/components/PillStrip'
import { Button } from '@/components/ui/button'

const fm = (n: number) => formatMoney(cents(n), getCurrency())

/**
 * The child-check strip (Task 13): one chip per child once an order has been split,
 * showing its check number and amount still due, with the child currently being
 * tendered highlighted. Purely presentational — SaleScreen owns which child is active
 * and swaps it in as the working `order` as each one closes. Rendered on `PillStrip`:
 * settled (due 0) wins over active, everything else waits as pending.
 */
export function SplitStrip({ orders, activeIx }: { orders: Order[]; activeIx: number }) {
  const items: PillStripItem[] = orders.map((child, ix) => ({
    label: `Check ${ix + 1}`,
    due: child.due_cents === 0 ? 'Paid' : fm(child.due_cents),
    state: child.due_cents === 0 ? 'settled' : ix === activeIx ? 'active' : 'pending',
  }))
  return <PillStrip items={items} />
}

/**
 * SPLIT ×N on the tender phase: a stepper bounded to 2–10 (SplitOrderRequest's `ways`
 * rule, so GO can never submit a value the API would reject) plus a live even-split
 * preview via money.ts's `allocate` — display only, a preview of what the server's own
 * exact allocator will produce, never the value actually sent (the server re-derives it
 * from the order at split time). GO/Cancel live in the action zone: while the prompt is
 * open, confirming the split IS the stage's primary action.
 */
export function SplitPrompt({ ways, totalCents, onWaysChange, onConfirm, onCancel, pending }: {
  ways: number
  totalCents: number
  onWaysChange: (ways: number) => void
  onConfirm: () => void
  onCancel: () => void
  pending: boolean
}) {
  const shares = allocate(cents(totalCents), ways)

  return (
    <div className="flex flex-col gap-md">
      <p className="type-body-sm text-ink-muted">Split into</p>
      <div className="flex items-center gap-sm" role="group" aria-label="Number of checks">
        <Button
          type="button" variant="tertiary" size="lg" className="min-w-[56px]"
          disabled={ways <= 2} onClick={() => onWaysChange(Math.max(2, ways - 1))}
        >
          −
        </Button>
        <span className="type-display-md type-money min-w-[48px] text-center">{ways}</span>
        <Button
          type="button" variant="tertiary" size="lg" className="min-w-[56px]"
          disabled={ways >= 10} onClick={() => onWaysChange(Math.min(10, ways + 1))}
        >
          +
        </Button>
      </div>
      <p className="type-body-sm type-money text-ink-muted">{shares.map((s) => fm(s)).join(' · ')}</p>
      <ActionZone>
        <Button size="xl" type="button" disabled={pending} onClick={onConfirm}>
          {pending ? 'Splitting…' : 'GO'}
        </Button>
        {/* Disabled while pending, not left clickable: once GO has been submitted the
            split is already in flight against the server and (on success) irreversible
            — the original order is voided server-side the moment it lands. Letting
            Cancel dismiss the prompt while that's still outstanding would let the
            cashier believe they'd backed out, only for split mode to activate under
            them anyway when the response arrives. */}
        <Button size="xl" type="button" variant="ghost" disabled={pending} onClick={onCancel}>Cancel</Button>
      </ActionZone>
    </div>
  )
}
