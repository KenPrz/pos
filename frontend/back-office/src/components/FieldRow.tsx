import type { ReactNode } from 'react'

export interface FieldRowProps {
  label: string
  error?: string
  children: ReactNode
}

// Label + control + error line, Carbon spacing.
export function FieldRow({ label, error, children }: FieldRowProps) {
  return (
    <div className="flex flex-col gap-xxs">
      <label className="type-body-sm text-ink-muted">
        <span className="mb-xxs block">{label}</span>
        {children}
      </label>
      {error ? <p className="type-caption text-error">{error}</p> : null}
    </div>
  )
}
