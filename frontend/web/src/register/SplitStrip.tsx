'use client'

import type { Order } from '../lib/api'
import { allocate, cents, formatMoney } from '../lib/money'

const CURRENCY = 'USD'
const fm = (n: number) => formatMoney(cents(n), CURRENCY)

/**
 * The child-check strip (Task 13): one chip per child once an order has been split,
 * showing its check number and amount still due, with the child currently being
 * tendered highlighted. Purely presentational — SaleScreen owns which child is active
 * and swaps it in as the working `order` as each one closes.
 */
export function SplitStrip({ orders, activeIx }: { orders: Order[]; activeIx: number }) {
  return (
    <div className="split-strip" role="group" aria-label="Split checks">
      {orders.map((child, ix) => (
        <div
          key={child.id}
          className={`split-chip${ix === activeIx ? ' active' : ''}${child.due_cents === 0 ? ' settled' : ''}`}
        >
          <span className="split-chip-num">Check {ix + 1}</span>
          <span className="split-chip-due num">{child.due_cents === 0 ? 'Paid' : fm(child.due_cents)}</span>
        </div>
      ))}
    </div>
  )
}

/**
 * SPLIT ×N on the tender phase: a stepper bounded to 2–10 (SplitOrderRequest's `ways`
 * rule, so GO can never submit a value the API would reject) plus a live even-split
 * preview via money.ts's `allocate` — display only, a preview of what the server's own
 * exact allocator will produce, never the value actually sent (the server re-derives it
 * from the order at split time).
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
    <div className="split-prompt">
      <p className="picker-label">Split into</p>
      <div className="stepper" role="group" aria-label="Number of checks">
        <button
          type="button" className="btn btn-secondary btn-chip"
          disabled={ways <= 2} onClick={() => onWaysChange(Math.max(2, ways - 1))}
        >
          −
        </button>
        <span className="stepper-value">{ways}</span>
        <button
          type="button" className="btn btn-secondary btn-chip"
          disabled={ways >= 10} onClick={() => onWaysChange(Math.min(10, ways + 1))}
        >
          +
        </button>
      </div>
      <p className="muted split-preview">{shares.map((s) => fm(s)).join(' · ')}</p>
      <div className="btn-row">
        <button type="button" className="btn btn-submit" disabled={pending} onClick={onConfirm}>
          {pending ? 'Splitting…' : 'GO'}
        </button>
        <button type="button" className="btn btn-secondary" onClick={onCancel}>Cancel</button>
      </div>
    </div>
  )
}
