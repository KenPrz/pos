import type { ReactNode } from 'react'

export interface SectionHeaderProps {
  title: string
  subline?: string
  action?: ReactNode
}

// display-md 300 title, ink-muted subline, right action slot.
export function SectionHeader({ title, subline, action }: SectionHeaderProps) {
  return (
    <div className="flex items-start justify-between gap-md border-b border-hairline pb-lg">
      <div>
        <h1 className="type-display-md text-ink">{title}</h1>
        {subline ? <p className="type-body-sm mt-xs text-ink-muted">{subline}</p> : null}
      </div>
      {action ? <div className="shrink-0">{action}</div> : null}
    </div>
  )
}
