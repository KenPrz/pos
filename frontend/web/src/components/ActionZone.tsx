import * as React from 'react'

export interface ActionZoneProps {
  children: React.ReactNode
}

// The register's fixed bottom action bar — the one place the stage's primary action
// lives (spec §register: action-zone bar 64px). Consumers pass ONE `Button size="xl"`
// (primary or danger) plus at most an optional ghost secondary; the bar stretches its
// children to fill the full width so the primary target is impossible to miss.
export function ActionZone({ children }: ActionZoneProps) {
  return (
    <div className="fixed inset-x-0 bottom-0 z-40 flex min-h-[64px] items-stretch border-t border-hairline bg-canvas *:flex-1 print:hidden">
      {children}
    </div>
  )
}
