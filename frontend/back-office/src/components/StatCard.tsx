import type { ReactNode } from 'react'
import { Card } from './ui/card'

export interface StatCardProps {
  label: string
  value: ReactNode
  meta?: ReactNode
}

// label caption, big weight-300 value, meta line.
export function StatCard({ label, value, meta }: StatCardProps) {
  return (
    <Card className="flex flex-col gap-xs">
      <p className="type-caption text-ink-muted">{label}</p>
      <p className="type-display-md type-money text-ink">{value}</p>
      {meta ? <p className="type-body-sm text-ink-muted">{meta}</p> : null}
    </Card>
  )
}
