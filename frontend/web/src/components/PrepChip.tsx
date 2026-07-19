import type { OrderLine } from '@/lib/api'
import { cn } from '@/lib/utils'

export type PrepState = NonNullable<OrderLine['prep_state']>

export interface PrepChipProps {
  state: PrepState
  onCycle: () => void
  disabled?: boolean
}

// The kitchen-state chip on a food-mode cart line: one tap advances the state
// (pending → in_progress → ready → …). The LABELS are the frozen contract —
// 'Pending' / 'Cooking' / 'Ready', exactly what the till says today. The screen
// owns the cycle order and its permission gate (ready → pending is supervisor-only);
// this chip just fires `onCycle`. 48px floor; color is state, not action —
// neutral until cooking (warning), success at ready.
const STATE_LABEL: Record<PrepState, string> = {
  pending: 'Pending',
  in_progress: 'Cooking',
  ready: 'Ready',
}

const STATE_TONE: Record<PrepState, string> = {
  pending: 'bg-canvas text-ink border-hairline',
  in_progress: 'bg-warning/20 text-warning-ink border-warning',
  ready: 'bg-success/15 text-success border-success',
}

export function PrepChip({ state, onCycle, disabled }: PrepChipProps) {
  return (
    <button
      type="button"
      onClick={onCycle}
      disabled={disabled}
      className={cn(
        'inline-flex min-h-[48px] shrink-0 items-center rounded-none border px-sm',
        'type-body-sm disabled:pointer-events-none disabled:opacity-50',
        'outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
        STATE_TONE[state]
      )}
    >
      {STATE_LABEL[state]}
    </button>
  )
}
