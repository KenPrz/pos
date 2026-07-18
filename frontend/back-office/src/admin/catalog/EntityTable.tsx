'use client'

import type { ReactNode } from 'react'

/** One column of a catalog list: a header label plus a per-row renderer. */
export type EntityColumn<T> = {
  header: string
  render: (row: T) => ReactNode
}

/**
 * The shared catalog list surface (Task 9): a `.bo-table` plate capped by the usual
 * `header.row` title bar, with a NEW button as its one primary action (DESIGN.md — warm
 * color means one action per screen). Generic over any entity so every catalog tab
 * (products, variants, categories, modifier groups, discounts, tax rates) reuses one
 * table instead of six near-identical ones.
 *
 * Archived rows (`is_active === false`) render greyed with an ARCHIVED badge and an
 * UNARCHIVE action — but only for entities that actually carry `is_active`. Categories
 * and modifier groups don't (verified against AdminCategoryResource /
 * AdminModifierGroupResource): `row.is_active === false` is simply never true for them,
 * so the badge and unarchive button never render, no special-casing needed here.
 */
export function EntityTable<T extends { id: string; is_active?: boolean }>({
  title,
  columns,
  rows,
  onEdit,
  onNew,
  onUnarchive,
  newLabel = 'New',
  // Warm signal orange by default — the table IS the screen when it's on top level, so
  // NEW is that screen's one primary action. When this table is nested inside an editor
  // that already has its own warm Save (ModifierGroupEditor's modifiers list, nested
  // under the group's own Save), the caller downgrades this to `btn-utility` so only one
  // warm button exists on screen at a time (DESIGN.md — warm color, one action).
  newButtonClass = 'btn-submit',
  emptyMessage = 'Nothing here yet.',
}: {
  title: string
  columns: Array<EntityColumn<T>>
  rows: T[]
  onEdit: (row: T) => void
  onNew: () => void
  onUnarchive?: (row: T) => void
  newLabel?: string
  newButtonClass?: string
  emptyMessage?: string
}) {
  return (
    <section className="form-panel">
      <header className="row">
        <h2>{title}</h2>
        <button type="button" className={`btn ${newButtonClass}`} onClick={onNew}>
          {newLabel}
        </button>
      </header>

      {rows.length === 0 ? (
        <p className="muted">{emptyMessage}</p>
      ) : (
        <table className="bo-table">
          <thead>
            <tr>
              {columns.map((column) => (
                <th key={column.header}>{column.header}</th>
              ))}
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const archived = row.is_active === false
              return (
                <tr key={row.id} className={archived ? 'archived-row' : undefined}>
                  {columns.map((column) => (
                    <td key={column.header}>{column.render(row)}</td>
                  ))}
                  <td>
                    <div className="btn-row">
                      <button type="button" className="btn btn-utility btn-chip" onClick={() => onEdit(row)}>
                        Edit
                      </button>
                      {archived && <span className="badge-archived">ARCHIVED</span>}
                      {archived && onUnarchive && (
                        <button
                          type="button"
                          className="btn btn-secondary btn-chip"
                          onClick={() => onUnarchive(row)}
                        >
                          Unarchive
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}
    </section>
  )
}
