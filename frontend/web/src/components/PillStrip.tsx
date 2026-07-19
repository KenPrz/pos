import { cn } from '@/lib/utils'
import { Badge } from '@/components/ui/badge'

export type PillState = 'active' | 'settled' | 'pending'

export interface PillStripItem {
  label: string
  due: string
  state: PillState
}

export interface PillStripProps {
  items: PillStripItem[]
}

// The split-payment strip: one chip per check, its label ("Check 1") and the amount
// still due as a preformatted string (the screen owns formatting — 'Paid' when
// settled). Purely presentational: `active` is the check being tendered now (info
// blue), `settled` is paid (success), `pending` is waiting its turn (neutral).
const STATE_VARIANT: Record<PillState, 'info' | 'success' | 'neutral'> = {
  active: 'info',
  settled: 'success',
  pending: 'neutral',
}

export function PillStrip({ items }: PillStripProps) {
  return (
    <div className="flex flex-wrap items-center gap-xs" role="group" aria-label="Split checks">
      {items.map((item, ix) => (
        <Badge
          key={ix}
          variant={STATE_VARIANT[item.state]}
          className={cn('min-h-[48px] gap-xs px-sm', item.state === 'active' && 'outline outline-2 -outline-offset-1 outline-primary')}
        >
          <span className="type-body-sm">{item.label}</span>
          <span className="type-body-sm type-money">{item.due}</span>
        </Badge>
      ))}
    </div>
  )
}
