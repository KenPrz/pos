export interface EmptyStateProps {
  title: string
  description?: string
}

// Icon-less by design (DESIGN.md carries no icon system): a card-title line and an
// optional body-sm ink-muted line.
export function EmptyState({ title, description }: EmptyStateProps) {
  return (
    <div className="flex flex-col items-center justify-center gap-xs px-lg py-xl text-center">
      <p className="type-card-title text-ink">{title}</p>
      {description ? <p className="type-body-sm text-ink-muted">{description}</p> : null}
    </div>
  )
}
