import * as React from 'react'
import { cn } from '@/lib/utils'

export type TileTone = 'success' | 'warning' | 'error' | 'info'

export interface TileButtonProps {
  title: string
  meta?: React.ReactNode
  edge?: TileTone
  onClick?: () => void
  disabled?: boolean
  hint?: string
}

const EDGE_TONE: Record<TileTone, string> = {
  success: 'border-l-success',
  warning: 'border-l-warning',
  error: 'border-l-error',
  info: 'border-l-primary',
}

// The big register tile (menu grid, floor view): ≥96px hairline tile, title plus
// meta lines, an optional 4px left edge in a semantic color for status, and an
// instant (no transition) surface shift when pressed. The tile IS the tap target —
// no inner buttons.
export function TileButton({ title, meta, edge, onClick, disabled, hint }: TileButtonProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={cn(
        'flex min-h-[96px] w-full flex-col items-start justify-start gap-xxs rounded-none',
        'border border-hairline bg-canvas px-md py-sm text-left text-ink',
        'active:bg-surface-2 disabled:pointer-events-none disabled:opacity-50',
        'outline-none focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary',
        edge && cn('border-l-4', EDGE_TONE[edge])
      )}
    >
      <span className="type-body-lg block">{title}</span>
      {meta != null && <span className="type-body-sm block text-ink-muted">{meta}</span>}
      {hint != null && <span className="type-caption block text-ink-subtle">{hint}</span>}
    </button>
  )
}
