'use client'

import type { ReactNode } from 'react'
import { DataTable, type DataTableColumn } from '../../components/DataTable'
import { StatusPill } from '../../components/StatusPill'
import { Button, type ButtonProps } from '../../components/ui/button'
import { CardTitle } from '../../components/ui/card'

/** One column of a catalog list: a header label plus a per-row renderer. */
export type EntityColumn<T> = {
  header: string
  render: (row: T) => ReactNode
}

/**
 * The shared catalog list surface: a `DataTable` capped by a title + NEW button
 * toolbar (DESIGN.md — one primary action per screen, so NEW stays `primary` by
 * default). Generic over any entity so every catalog tab (products, variants,
 * categories, modifier groups, discounts, tax rates) reuses one table instead of six
 * near-identical ones.
 *
 * Archived rows (`is_active === false`) render greyed with an ARCHIVED `StatusPill`
 * and an UNARCHIVE ghost `Button` — but only for entities that actually carry
 * `is_active`. Categories and modifier groups don't (verified against
 * AdminCategoryResource / AdminModifierGroupResource): `row.is_active === false` is
 * simply never true for them, so the badge and unarchive button never render, no
 * special-casing needed here.
 */
export function EntityTable<T extends { id: string; is_active?: boolean }>({
  title,
  columns,
  rows,
  onEdit,
  onNew,
  onUnarchive,
  newLabel = 'New',
  // `primary` by default — the table IS the screen when it's on top level, so NEW is
  // that screen's one primary action. When this table is nested inside an editor that
  // already has its own primary Save (ModifierGroupEditor's modifiers list, nested
  // under the group's own Save), the caller downgrades this to `tertiary` so only one
  // primary button exists on screen at a time (DESIGN.md — one primary action).
  newButtonVariant = 'primary',
  emptyMessage = 'Nothing here yet.',
  // Users (Task 4) reuse this same grey-out-and-reinstate mechanics for "deactivated"
  // rather than "archived" — different vocabulary, identical is_active PATCH underneath —
  // so the two labels are overridable rather than hardcoded to the catalog's wording.
  archivedLabel = 'ARCHIVED',
  unarchiveLabel = 'Unarchive',
}: {
  title: string
  columns: Array<EntityColumn<T>>
  rows: T[]
  onEdit: (row: T) => void
  onNew: () => void
  onUnarchive?: (row: T) => void
  newLabel?: string
  newButtonVariant?: ButtonProps['variant']
  emptyMessage?: string
  archivedLabel?: string
  unarchiveLabel?: string
}) {
  const dataColumns: DataTableColumn<T>[] = [
    ...columns.map((column, index) => ({
      key: `col-${index}`,
      header: column.header,
      render: column.render,
    })),
    {
      key: 'actions',
      header: 'Actions',
      render: (row: T) => {
        const archived = row.is_active === false
        return (
          <div className="flex items-center gap-xs">
            <Button type="button" variant="ghost" onClick={() => onEdit(row)}>
              Edit
            </Button>
            {archived && <StatusPill tone="neutral">{archivedLabel}</StatusPill>}
            {archived && onUnarchive && (
              <Button type="button" variant="ghost" onClick={() => onUnarchive(row)}>
                {unarchiveLabel}
              </Button>
            )}
          </div>
        )
      },
    },
  ]

  return (
    <DataTable<T>
      columns={dataColumns}
      rows={rows}
      rowKey={(row) => row.id}
      empty={{ title: emptyMessage }}
      toolbar={
        <>
          <CardTitle>{title}</CardTitle>
          <Button type="button" variant={newButtonVariant} onClick={onNew}>
            {newLabel}
          </Button>
        </>
      }
    />
  )
}
