// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { EntityTable } from './EntityTable'

afterEach(cleanup)

type Row = { id: string; name: string; is_active?: boolean }

const COLUMNS = [{ header: 'Name', render: (row: Row) => row.name }]

describe('EntityTable', () => {
  it('renders one row per item with its column values', () => {
    render(
      <EntityTable<Row>
        title="Products"
        columns={COLUMNS}
        rows={[
          { id: '1', name: 'Latte', is_active: true },
          { id: '2', name: 'Cortado', is_active: true },
        ]}
        onEdit={vi.fn()}
        onNew={vi.fn()}
      />,
    )

    expect(screen.getByText('Latte')).toBeInTheDocument()
    expect(screen.getByText('Cortado')).toBeInTheDocument()
  })

  it('shows an ARCHIVED badge and UNARCHIVE action only for is_active: false rows', () => {
    render(
      <EntityTable<Row>
        title="Products"
        columns={COLUMNS}
        rows={[
          { id: '1', name: 'Latte', is_active: true },
          { id: '2', name: 'Discontinued Scone', is_active: false },
        ]}
        onEdit={vi.fn()}
        onNew={vi.fn()}
        onUnarchive={vi.fn()}
      />,
    )

    expect(screen.getAllByText('ARCHIVED')).toHaveLength(1)
    expect(screen.getAllByRole('button', { name: /unarchive/i })).toHaveLength(1)

    // Regression: archived rows used to dim via a hardcoded `archived-row` CSS class —
    // restored here as `DataTable`'s data-driven `inactive` prop (opacity-55).
    expect(screen.getByText('Discontinued Scone').closest('tr')).toHaveClass('opacity-55')
    expect(screen.getByText('Latte').closest('tr')).not.toHaveClass('opacity-55')
  })

  it('never shows the badge for rows with no is_active field at all (categories, modifier groups)', () => {
    render(
      <EntityTable<Row>
        title="Categories"
        columns={COLUMNS}
        rows={[{ id: '1', name: 'Beverages' }]}
        onEdit={vi.fn()}
        onNew={vi.fn()}
      />,
    )

    expect(screen.queryByText('ARCHIVED')).not.toBeInTheDocument()
  })

  it('fires onEdit with the row when its Edit button is clicked', () => {
    const onEdit = vi.fn()
    const row = { id: '2', name: 'Cortado', is_active: true }
    render(<EntityTable<Row> title="Products" columns={COLUMNS} rows={[row]} onEdit={onEdit} onNew={vi.fn()} />)

    fireEvent.click(screen.getByRole('button', { name: /edit/i }))

    expect(onEdit).toHaveBeenCalledWith(row)
  })

  it('fires onUnarchive with the row when its Unarchive button is clicked', () => {
    const onUnarchive = vi.fn()
    const row = { id: '2', name: 'Discontinued Scone', is_active: false }
    render(
      <EntityTable<Row> title="Products" columns={COLUMNS} rows={[row]} onEdit={vi.fn()} onNew={vi.fn()} onUnarchive={onUnarchive} />,
    )

    fireEvent.click(screen.getByRole('button', { name: /unarchive/i }))

    expect(onUnarchive).toHaveBeenCalledWith(row)
  })

  it('fires onNew when the New button is clicked', () => {
    const onNew = vi.fn()
    render(<EntityTable<Row> title="Products" columns={COLUMNS} rows={[]} onEdit={vi.fn()} onNew={onNew} />)

    fireEvent.click(screen.getByRole('button', { name: /new/i }))

    expect(onNew).toHaveBeenCalled()
  })

  it('shows the empty message when there are no rows', () => {
    render(<EntityTable<Row> title="Products" columns={COLUMNS} rows={[]} onEdit={vi.fn()} onNew={vi.fn()} emptyMessage="No products yet." />)

    expect(screen.getByText('No products yet.')).toBeInTheDocument()
  })
})
