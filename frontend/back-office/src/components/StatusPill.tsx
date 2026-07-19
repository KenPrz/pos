import * as React from 'react'
import { cn } from '@/lib/utils'

export type StatusPillTone = 'success' | 'warning' | 'error' | 'info' | 'neutral'

export interface StatusPillProps {
  tone: StatusPillTone
  children: React.ReactNode
}

const DOT_TONE: Record<StatusPillTone, string> = {
  success: 'bg-success',
  warning: 'bg-warning',
  error: 'bg-error',
  info: 'bg-primary',
  neutral: 'bg-ink-subtle',
}

// Dot + sentence-case label, semantic color. Not a pill container — Badge is the
// only sanctioned pill shape; this is a status indicator, not a chip.
export function StatusPill({ tone, children }: StatusPillProps) {
  return (
    <span className="inline-flex items-center gap-xs text-[14px] leading-[1.29] tracking-[0.16px] text-ink">
      <span aria-hidden className={cn('h-[8px] w-[8px] shrink-0 rounded-full', DOT_TONE[tone])} />
      {children}
    </span>
  )
}
