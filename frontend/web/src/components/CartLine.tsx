import * as React from 'react'
import type { OrderLine } from '@/lib/api'
import { getCurrency } from '@/lib/currency'
import { cents, formatMoney } from '@/lib/money'
import { Button } from '@/components/ui/button'

export interface CartLineProps {
  line: OrderLine
  onVoid?: () => void
  prepChip?: React.ReactNode
}

// One cart row: name (with the modifier list indented beneath it), quantity when it
// isn't exactly one, and the line total right-aligned in tabular figures. `prepChip`
// is a slot — the screen decides whether a `PrepChip` belongs (food mode, tracked
// lines only) — and `onVoid` present is what makes the void affordance appear, so
// permission gating stays the screen's call.
export function CartLine({ line, onVoid, prepChip }: CartLineProps) {
  return (
    <div className="flex min-h-[48px] items-center gap-sm border-b border-hairline px-md py-sm text-ink">
      <div className="min-w-0 flex-1">
        <span className="type-body-lg block">{line.name}</span>
        {line.modifiers && line.modifiers.length > 0 && (
          <ul className="type-body-sm pl-md text-ink-muted">
            {line.modifiers.map((m, i) => (
              <li key={i}>
                {m.name}
                {m.price_delta_cents !== 0 && (
                  <span className="type-money"> {formatMoney(cents(m.price_delta_cents), getCurrency())}</span>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
      {line.qty !== '1.000' && (
        <span className="type-body-lg type-money shrink-0 text-ink-muted">{line.qty}</span>
      )}
      <span className="type-body-lg type-money shrink-0 text-right">
        {formatMoney(cents(line.line_total_cents), getCurrency())}
      </span>
      {prepChip}
      {onVoid && (
        <Button type="button" variant="ghost" onClick={onVoid} className="min-h-[48px] shrink-0 text-error">
          Void
        </Button>
      )}
    </div>
  )
}
